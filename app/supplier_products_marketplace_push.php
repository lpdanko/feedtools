<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ops.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/supplier_products.php';
require_once __DIR__ . '/text_sanitize.php';
require_once __DIR__ . '/ozon_price_tool.php';
require_once __DIR__ . '/ozon_products.php';
require_once __DIR__ . '/marketplace_brand_dictionary.php';
require_once __DIR__ . '/wildberries/WildberriesClient.php';
require_once __DIR__ . '/wildberries/WildberriesPriceTool.php';
require_once __DIR__ . '/wildberries/WildberriesProducts.php';
require_once __DIR__ . '/wildberries/WbRichContent.php';

function supplier_push_lc(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function supplier_push_norm_name(string $value): string
{
    $value = str_replace('ё', 'е', supplier_push_lc(trim($value)));
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string)$value);
    $value = preg_replace('/\s+/u', ' ', (string)$value);
    return trim((string)$value);
}

function supplier_push_attribute_alias_groups(): array
{
    return [
        'color' => ['цвет', 'цвет товара', 'название цвета', 'основной цвет'],
        'material' => ['материал', 'материал изделия', 'основной материал', 'материал корпуса'],
        'country_of_origin' => ['страна производства', 'страна-производитель', 'страна производитель', 'страна-изготовитель', 'страна изготовитель', 'страна происхождения'],
        'unit_quantity' => ['количество товара в уеи', 'количество_в_единице_товара', 'количество в единице товара', 'количество в упаковке шт', 'количество штук в упаковке', 'количество товаров в упаковке', 'количество в упаковке', 'количество единиц в товаре', 'единиц в одном товаре'],
        'model_name' => ['модель', 'модель товара', 'название модели', 'название модели для объединения в одну карточку'],
        'compatible_models' => ['совместимые модели', 'совместимые модели устройства', 'совместимые модели товара', 'модели совместимости', 'модель совместимости', 'совместимость с моделями', 'подходит для моделей'],
        'tnved' => ['тн вэд', 'тн вэд коды еаэс', 'тнвэд', 'код тн вэд', 'код тнвэд', 'tn ved', 'tnved', 'tnved code'],
    ];
}

function supplier_push_attribute_alias_names(string $name): array
{
    $name = trim($name);
    $names = [];
    if ($name !== '') {
        $names[] = $name;
    }
    $norm = supplier_push_norm_name($name);
    if ($norm === '') {
        return $names;
    }

    foreach (supplier_push_attribute_alias_groups() as $group) {
        $groupNorms = [];
        foreach ($group as $alias) {
            $alias = trim((string)$alias);
            if ($alias === '') {
                continue;
            }
            $aliasNorm = supplier_push_norm_name($alias);
            if ($aliasNorm !== '') {
                $groupNorms[$aliasNorm] = true;
            }
        }
        if (isset($groupNorms[$norm])) {
            foreach ($group as $alias) {
                $alias = trim((string)$alias);
                if ($alias !== '') {
                    $names[] = $alias;
                }
            }
        }
    }

    $out = [];
    $seen = [];
    foreach ($names as $candidate) {
        $candidate = trim((string)$candidate);
        $candidateNorm = supplier_push_norm_name($candidate);
        if ($candidate === '' || $candidateNorm === '' || isset($seen[$candidateNorm])) {
            continue;
        }
        $seen[$candidateNorm] = true;
        $out[] = $candidate;
    }
    return $out;
}

function supplier_push_split_attribute_values(array $values): array
{
    $out = [];
    $seen = [];
    foreach ($values as $value) {
        if (is_array($value)) {
            $value = trim((string)($value['value'] ?? ($value['name'] ?? '')));
        } else {
            $value = trim((string)$value);
        }
        if ($value === '') {
            continue;
        }
        $parts = preg_split('/\s*[;\|\n\r]+\s*/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [$value];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            $key = supplier_push_norm_name($part);
            if ($part === '' || $key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $part;
        }
    }
    return $out;
}

function supplier_push_first_attribute_value(array $values): array
{
    foreach ($values as $value) {
        if (is_array($value)) {
            $value = (string)($value['value'] ?? ($value['name'] ?? ''));
        }
        $parts = preg_split('~\s*(?:/|;|\||,|\R|\s+\+\s+|\s+и\s+)\s*~iu', (string)$value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            $part = supplier_push_norm_spaces((string)$part);
            if ($part !== '') {
                return [$part];
            }
        }
    }
    return [];
}

function supplier_push_brand_is_empty_like(string $brand): bool
{
    $norm = function_exists('marketplace_brand_dictionary_norm')
        ? marketplace_brand_dictionary_norm($brand)
        : supplier_push_norm_name($brand);
    if ($norm === '') {
        return true;
    }
    return in_array($norm, [
        '-',
        'нет',
        'нет бренда',
        'без бренда',
        'no brand',
        'nobrand',
        'none',
        'unknown',
    ], true);
}

function supplier_push_ozon_is_color_name_single_meta(array $meta): bool
{
    $name = supplier_push_norm_name((string)($meta['name'] ?? ''));
    return in_array($name, [
        'название цвета',
        'название цвета товара',
    ], true);
}

function supplier_push_ozon_attribute_max_count(array $meta): int
{
    foreach (['max_count', 'maxCount', 'max_value_count', 'maxValueCount', 'max_values', 'maxValues'] as $key) {
        if (array_key_exists($key, $meta)) {
            $value = (int)$meta[$key];
            if ($value > 0) {
                return $value;
            }
        }
    }
    if (array_key_exists('is_collection', $meta) && empty($meta['is_collection'])) {
        return 1;
    }
    if (supplier_push_ozon_is_color_name_single_meta($meta)) {
        return 1;
    }
    return 0;
}

function supplier_push_ozon_is_warranty_two_years_meta(array $meta): bool
{
    $name = supplier_push_norm_name((string)($meta['name'] ?? ''));
    return in_array($name, [
        'гарантия',
        'гарантийный срок',
        'срок гарантии',
    ], true);
}

function supplier_push_ozon_warranty_value_for_meta(array $meta): string
{
    return supplier_push_ozon_is_warranty_two_years_meta($meta) ? '2 года' : '';
}

function supplier_push_ozon_brand_names_for_category(string $ozonCategory): array
{
    static $cache = [];

    $ozonCategory = trim($ozonCategory);
    if ($ozonCategory === '') {
        return [];
    }
    if (array_key_exists($ozonCategory, $cache)) {
        return $cache[$ozonCategory];
    }
    if (!preg_match('~^(\d+)_(\d+)$~', $ozonCategory, $m)) {
        return $cache[$ozonCategory] = [];
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
        return $cache[$ozonCategory] = $names;
    } catch (Throwable $e) {
        return $cache[$ozonCategory] = [];
    }
}

function supplier_push_ozon_fits_for_brand_aliases(): array
{
    return [
        'apple' => 'Apple',
        'iphone' => 'Apple',
        'ipad' => 'Apple',
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
}

function supplier_push_ozon_fits_for_brand_values(array $values, array $bundle, array $taxonomyMeta): array
{
    $product = (array)($bundle['product'] ?? []);
    $ozonCategory = (string)($product['ozon_category'] ?? '');
    $fallbackBrands = [];
    foreach ([
        $bundle['brand_ozon'] ?? '',
        $bundle['brand'] ?? '',
        $product['brand'] ?? '',
    ] as $brand) {
        $brand = trim((string)$brand);
        if ($brand !== '' && !supplier_push_brand_is_empty_like($brand)) {
            $fallbackBrands[] = $brand;
        }
    }

    $brandMap = [];
    $addBrand = static function (string $alias, string $brand) use (&$brandMap): void {
        $alias = trim((string)preg_replace('~\s+~u', ' ', $alias));
        $brand = trim((string)preg_replace('~\s+~u', ' ', $brand));
        $aliasNorm = supplier_push_norm_name($alias);
        $brandNorm = supplier_push_norm_name($brand);
        if ($aliasNorm !== '' && $brand !== '') {
            $brandMap[$aliasNorm] = $brand;
        }
        if ($brandNorm !== '' && $brand !== '') {
            $brandMap[$brandNorm] = $brand;
        }
    };

    foreach (supplier_push_ozon_brand_names_for_category($ozonCategory) as $brand) {
        $addBrand((string)$brand, (string)$brand);
    }
    foreach (supplier_push_ozon_fits_for_brand_aliases() as $alias => $brand) {
        $addBrand((string)$alias, (string)$brand);
    }
    foreach ($fallbackBrands as $brand) {
        $addBrand($brand, $brand);
    }

    $skipBrandNorms = array_fill_keys([
        'air', 'unit', 'pro', 'mini', 'max', 'plus', 'fpv', 'hd', 'uhd', 'go', 'mi',
        'для', 'товар', 'товара', 'модель', 'серия', 'версии',
        'дрон', 'дроны', 'квадрокоптер', 'квадрокоптеры', 'телефон', 'смартфон',
        'аккумулятор', 'батарея', 'контроллер', 'пульт', 'модуль', 'камера',
    ], true);

    uksort($brandMap, static function ($a, $b): int {
        return mb_strlen((string)$b, 'UTF-8') <=> mb_strlen((string)$a, 'UTF-8');
    });

    $out = [];
    $seen = [];
    $scan = static function (array $scanValues) use (&$out, &$seen, $brandMap, $skipBrandNorms): void {
        foreach ($scanValues as $raw) {
            $raw = trim((string)preg_replace('~\s+~u', ' ', (string)$raw));
            if ($raw === '') {
                continue;
            }
            $raw = (string)preg_replace(
                '~^\s*(?:подходит\s+для|совместим(?:о|а|ый|ые)?\s+(?:с|для)?|для)\s*[:=\-–—]?\s*~iu',
                '',
                $raw
            );
            $rawNorm = supplier_push_norm_name($raw);
            if ($rawNorm === '') {
                continue;
            }
            $haystack = ' ' . $rawNorm . ' ';
            foreach ($brandMap as $brandNorm => $brandName) {
                $brandNorm = (string)$brandNorm;
                if ($brandNorm === '' || isset($skipBrandNorms[$brandNorm]) || preg_match('~^\d+$~u', $brandNorm)) {
                    continue;
                }
                $shortBrand = mb_strlen($brandNorm, 'UTF-8') < 3;
                if ($shortBrand && $rawNorm !== $brandNorm) {
                    continue;
                }
                if (!$shortBrand && !preg_match('~(^| )' . preg_quote($brandNorm, '~') . '($| )~u', $haystack)) {
                    continue;
                }
                $key = supplier_push_norm_name((string)$brandName);
                if ($key !== '' && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = (string)$brandName;
                }
                if (count($out) >= 6) {
                    return;
                }
            }
        }
    };

    $scan(supplier_push_split_attribute_values($values));
    if (!$out) {
        $hints = [
            $bundle['name'] ?? '',
            $bundle['model'] ?? '',
            $bundle['description'] ?? '',
        ];
        foreach ((array)($bundle['params'] ?? []) as $name => $paramValues) {
            $hints[] = (string)$name . ': ' . implode('; ', array_map('strval', (array)$paramValues));
            if (count($hints) >= 80) {
                break;
            }
        }
        $scan($hints);
    }
    if (!$out) {
        foreach ($fallbackBrands as $brand) {
            $key = supplier_push_norm_name($brand);
            if ($key !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $brand;
            }
        }
    }

    return $out;
}

function supplier_push_primary_model_text(string $value): string
{
    $value = supplier_push_norm_spaces($value);
    if ($value === '') {
        return '';
    }

    $parts = preg_split('~\s*(?:/|;|\||,|\R|\s+\+\s+|\s+и\s+)\s*~iu', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($parts) > 1) {
        foreach ($parts as $part) {
            $part = trim((string)$part, " \t\n\r\0\x0B.,;:/|+");
            if ($part !== '') {
                return supplier_push_limit_text($part, 120);
            }
        }
    }

    $tokens = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($tokens) > 1) {
        $allModelCodes = true;
        foreach ($tokens as $token) {
            if (!preg_match('~^(?=.*\d)[\p{L}\p{N}][\p{L}\p{N}._#\-]{1,40}$~u', (string)$token)) {
                $allModelCodes = false;
                break;
            }
        }
        if ($allModelCodes) {
            return supplier_push_limit_text((string)$tokens[0], 120);
        }
    }

    return supplier_push_limit_text($value, 120);
}

function supplier_push_values_for_attribute_names(array $sourceMaps, array $names): array
{
    $candidateNorms = [];
    foreach ($names as $name) {
        foreach (supplier_push_attribute_alias_names((string)$name) as $candidate) {
            $norm = supplier_push_norm_name($candidate);
            if ($norm !== '') {
                $candidateNorms[$norm] = true;
            }
        }
    }
    if (!$candidateNorms) {
        return [];
    }

    $values = [];
    foreach ($sourceMaps as $sourceMap) {
        foreach ((array)$sourceMap as $name => $rawValues) {
            $norm = supplier_push_norm_name((string)$name);
            if ($norm === '' || !isset($candidateNorms[$norm])) {
                continue;
            }
            foreach (supplier_push_split_attribute_values((array)$rawValues) as $value) {
                $values[] = $value;
            }
        }
    }
    return supplier_push_split_attribute_values($values);
}

function supplier_push_video_cover_field_names(): array
{
    if (function_exists('supplier_products_video_cover_field_names')) {
        $names = supplier_products_video_cover_field_names();
        if (is_array($names) && $names) {
            return array_values(array_unique(array_merge($names, [
                'WB.Видео: ссылка',
                'Wildberries.Video: link',
            ])));
        }
    }
    return [
        'Озон.Видеообложка: ссылка',
        'Ozon.VideoCover: link',
        'Видеообложка',
        'Видео-обложка',
        'WB.Видео: ссылка',
        'Wildberries.Video: link',
    ];
}

function supplier_push_video_cover_url(array $bundle): string
{
    $values = supplier_push_values_for_attribute_names([
        (array)($bundle['params'] ?? []),
        (array)($bundle['wb_params'] ?? []),
    ], supplier_push_video_cover_field_names());
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '' && supplier_push_is_http_url($value)) {
            return $value;
        }
    }
    return '';
}

function supplier_push_text(string $value): string
{
    return ft_text_normalize_space(ft_markup_to_plain_text($value));
}

function supplier_push_inline_text(string $value): string
{
    return ft_text_normalize_inline(supplier_push_text($value));
}

function supplier_push_limit_text(string $text, int $maxLen): string
{
    $text = trim((string)$text);
    if ($text === '' || $maxLen <= 0) {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') <= $maxLen) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $maxLen, 'UTF-8'), " \t\n\r\0\x0B.,;:-");
    }
    if (strlen($text) <= $maxLen) {
        return $text;
    }
    return rtrim(substr($text, 0, $maxLen), " \t\n\r\0\x0B.,;:-");
}

function supplier_push_wb_title_limit(): int
{
    return 200;
}

function supplier_push_norm_spaces(string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/[\r\n\t]+/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', (string)$value);
    return trim((string)$value);
}

function supplier_push_title_plain_text(string $value): string
{
    $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('~<\s*br\s*/?\s*>~iu', ' ', (string)$value);
    $value = strip_tags((string)$value);
    return supplier_push_norm_spaces((string)$value);
}

function supplier_push_remove_token_from_title(string $title, string $token): string
{
    $title = supplier_push_norm_spaces($title);
    $token = supplier_push_norm_spaces($token);
    if ($title === '' || $token === '') {
        return $title;
    }

    $pattern = preg_quote($token, '/');
    $clean = preg_replace('/(?<!\p{L}|\p{N})' . $pattern . '(?!\p{L}|\p{N})/iu', ' ', $title);
    if ((string)$clean === $title) {
        return $title;
    }
    $clean = preg_replace('/\s*([,;:\/\-])\s*/u', '$1 ', (string)$clean);
    $clean = preg_replace('/([\(\[])\s+/u', '$1', (string)$clean);
    $clean = preg_replace('/\s*([\)\]])\s*/u', '$1 ', (string)$clean);
    $clean = preg_replace('/(?:^| )[,;:\/\-]+(?= )/u', ' ', (string)$clean);
    $clean = preg_replace('~\s+([\)\]\},;:.!?])~u', '$1', (string)$clean);
    $clean = preg_replace('~([\(\[\{])\s+~u', '$1', (string)$clean);
    $clean = preg_replace('/\s+/u', ' ', (string)$clean);
    $clean = trim((string)$clean, " \t\n\r\0\x0B-–—,;:/()[]");
    return $clean !== '' ? $clean : $title;
}

function supplier_push_offer_id_without_supplier(string $offerId): string
{
    $offerId = supplier_push_norm_spaces($offerId);
    if ($offerId === '') {
        return '';
    }
    $pos = strpos($offerId, '__');
    return $pos === false ? $offerId : trim((string)substr($offerId, 0, $pos));
}

function supplier_push_wb_title_cleanup_tokens(array $bundle, string $brand, string $vendorCode): array
{
    $tokens = [];
    $add = static function ($value) use (&$tokens): void {
        $value = supplier_push_norm_spaces((string)$value);
        if ($value === '') {
            return;
        }
        $valueNorm = supplier_push_norm_name($value);
        if ($valueNorm === '' || in_array($valueNorm, ['нет бренда', 'no brand', 'nobrand'], true)) {
            return;
        }
        $tokens[$value] = true;
        $core = supplier_push_offer_id_without_supplier($value);
        if ($core !== '' && $core !== $value) {
            $tokens[$core] = true;
        }
    };

    $product = (array)($bundle['product'] ?? []);
    $tags = (array)($bundle['tags'] ?? []);
    $standard = (array)($bundle['standard'] ?? []);
    foreach ([
        $brand,
        $bundle['brand_wb'] ?? '',
        $bundle['brand'] ?? '',
        $standard['brand_wb'] ?? '',
        $standard['brand'] ?? '',
        $product['brand'] ?? '',
        $vendorCode,
        $bundle['offer_id'] ?? '',
        $bundle['vendor_code'] ?? '',
        $product['offer_id'] ?? '',
        $product['raw_offer_id'] ?? '',
        $product['vendor_code'] ?? '',
        $tags['vendorCode'] ?? '',
        $tags['article'] ?? '',
        $tags['offer_id'] ?? '',
        $tags['offerId'] ?? '',
    ] as $candidate) {
        $add($candidate);
    }

    $bundleInfo = (array)($bundle['bundle_info'] ?? []);
    foreach ([
        $bundleInfo['base_offer_id'] ?? '',
        $bundleInfo['raw_base_offer_id'] ?? '',
    ] as $candidate) {
        $add($candidate);
    }

    $out = array_map('strval', array_keys($tokens));
    usort($out, static function (string $a, string $b): int {
        return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
    });
    return $out;
}

function supplier_push_wb_title_for_payload(array $bundle, string $brand, string $vendorCode): string
{
    $standard = (array)($bundle['standard'] ?? []);
    $shortName = supplier_push_title_plain_text((string)($standard['short_name'] ?? ''));
    $name = $shortName !== '' ? $shortName : supplier_push_title_plain_text((string)($bundle['name'] ?? ''));
    if ($name === '') {
        return '';
    }
    $limit = $shortName !== '' ? min(60, supplier_push_wb_title_limit()) : supplier_push_wb_title_limit();

    $title = $name;
    foreach (supplier_push_wb_title_cleanup_tokens($bundle, $brand, $vendorCode) as $token) {
        $title = supplier_push_remove_token_from_title($title, $token);
    }
    $title = supplier_push_norm_spaces($title);
    if ($title === '') {
        $title = $name;
    }
    return supplier_push_limit_text($title, $limit);
}

function supplier_push_wb_title_brand(array $bundle, ?array $card = null, string $payloadBrand = ''): string
{
    foreach ([
        $payloadBrand,
        (string)($bundle['brand_wb'] ?? ''),
        (string)($bundle['brand'] ?? ''),
        is_array($card) ? (string)($card['brand'] ?? '') : '',
    ] as $candidate) {
        $candidate = supplier_push_norm_spaces($candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }
    return '';
}

function supplier_push_wb_payload_text($value, int $maxLen): string
{
    $text = ft_sanitize_plain_text((string)$value);
    if ($text === '') {
        $text = supplier_push_title_plain_text((string)$value);
    }
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', (string)$text);
    $text = supplier_push_norm_spaces((string)$text);
    return supplier_push_limit_text($text, $maxLen);
}

function supplier_push_wb_description_limit(): int
{
    return 2000;
}

function supplier_push_wb_description_for_payload(string $description, string $fallback = ''): string
{
    return wb_rich_content_description($description, $fallback, supplier_push_wb_description_limit());
}

function supplier_push_bundle_description(array $product, array $tags): string
{
    $candidates = [
        $product['description_html'] ?? '',
        $product['description'] ?? '',
        $tags['description'] ?? '',
        $tags['Описание'] ?? '',
        $tags['opisanie'] ?? '',
    ];
    foreach ($candidates as $candidate) {
        $text = supplier_push_text((string)$candidate);
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function supplier_push_ozon_title_for_payload(string $name): string
{
    return supplier_push_limit_text(supplier_push_title_plain_text($name), 500);
}

function supplier_push_bundle_model_value(array $bundle): string
{
    $tags = (array)($bundle['tags'] ?? []);
    $standard = (array)($bundle['standard'] ?? []);
    $params = (array)($bundle['params'] ?? []);
    $product = (array)($bundle['product'] ?? []);

    $candidates = [
        $bundle['model'] ?? '',
        $tags['same_model'] ?? '',
        $tags['model'] ?? '',
        $standard['model'] ?? '',
        $product['model'] ?? '',
    ];
    foreach ($params as $name => $values) {
        if (supplier_push_is_model_attribute_name((string)$name)) {
            foreach ((array)$values as $value) {
                $candidates[] = $value;
            }
        }
    }

    foreach ($candidates as $candidate) {
        $candidate = supplier_push_primary_model_text(supplier_push_inline_text((string)$candidate));
        if ($candidate !== '') {
            return supplier_push_limit_text($candidate, 120);
        }
    }
    return '';
}

function supplier_push_is_model_attribute_name(string $name): bool
{
    $norm = supplier_push_norm_name($name);
    if ($norm === '') {
        return false;
    }
    foreach ([
        'Модель',
        'Модель товара',
        'Название модели',
        'Название модели (для объединения в одну карточку)',
        'Название модели для объединения в одну карточку',
        'Название группы',
    ] as $alias) {
        foreach (supplier_push_attribute_alias_names($alias) as $candidate) {
            if ($norm === supplier_push_norm_name((string)$candidate)) {
                return true;
            }
        }
    }
    return false;
}

function supplier_push_hashtag_parts(string $value): array
{
    $out = [];
    $seen = [];
    $value = trim((string)$value);
    if ($value === '') {
        return [];
    }
    $parts = preg_split('/[\s,;]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '') {
            continue;
        }
        $part = ltrim($part, '#');
        $part = preg_replace('/[^\p{L}\p{N}_]+/u', '_', (string)$part);
        $part = preg_replace('/_+/u', '_', (string)$part);
        $part = trim((string)$part, '_');
        if ($part === '') {
            continue;
        }
        $part = supplier_push_limit_text($part, 29);
        $part = trim((string)$part, '_');
        if ($part === '') {
            continue;
        }
        $tag = '#' . $part;
        $key = supplier_push_lc($tag);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $tag;
        if (count($out) >= 30) {
            break;
        }
    }
    return $out;
}

function supplier_push_bundle_hashtags_value(array $bundle): string
{
    $tags = (array)($bundle['tags'] ?? []);
    $params = (array)($bundle['params'] ?? []);
    $values = [];
    foreach (['hashtags', '#Хештеги', 'хештеги', 'хэштеги'] as $key) {
        if (isset($tags[$key])) {
            $values[] = (string)$tags[$key];
        }
    }
    foreach ($params as $name => $rawValues) {
        if (supplier_products_is_hashtags_field_name((string)$name)) {
            foreach ((array)$rawValues as $value) {
                $values[] = (string)$value;
            }
        }
    }

    $out = [];
    $seen = [];
    foreach ($values as $value) {
        foreach (supplier_push_hashtag_parts((string)$value) as $tag) {
            $key = supplier_push_lc($tag);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $tag;
            if (count($out) >= 30) {
                break 2;
            }
        }
    }
    return implode(' ', $out);
}

function supplier_push_ozon_rich_text_chunks(string $descriptionHtml, int $maxChunks = 200): array
{
    $plain = supplier_push_text(ft_sanitize_markupish_text($descriptionHtml));
    if ($plain === '') {
        return [];
    }
    $plain = preg_replace('/\R{3,}/u', "\n\n", (string)$plain);
    $blocks = preg_split('/\n\s*\n/u', (string)$plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($blocks as $block) {
        $lines = preg_split('/\R/u', trim((string)$block), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($lines as $line) {
            $line = ft_text_normalize_inline((string)$line);
            if ($line === '') {
                continue;
            }
            if ((function_exists('mb_strlen') ? mb_strlen($line, 'UTF-8') : strlen($line)) > 320) {
                $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [$line];
                foreach ($sentences as $sentence) {
                    $sentence = supplier_push_limit_text(ft_text_normalize_inline((string)$sentence), 320);
                    if ($sentence !== '') {
                        $out[] = $sentence;
                    }
                    if (count($out) >= $maxChunks) {
                        break 2;
                    }
                }
            } else {
                $out[] = $line;
            }
            if (count($out) >= $maxChunks) {
                break 2;
            }
        }
    }

    $unique = [];
    $seen = [];
    foreach ($out as $item) {
        $key = supplier_push_lc((string)$item);
        if ($item === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $item;
    }
    return array_slice($unique, 0, $maxChunks);
}

function supplier_push_ozon_rich_param_exclusions(): array
{
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $names = [
        'видео',
        'Озон.Видео: название',
        'Озон.Видео: ссылка',
        'Озон.Видео: товары на видео',
        'Озон.Видеообложка: ссылка',
        'rich-контент json',
        'rich content json',
        '#хештеги',
        'хештеги',
        'barcode',
        'штрихкод',
        'артикул',
        'артикул ozon',
        'sku',
        'same_model',
        'название модели',
        'название модели для объединения в одну карточку',
        'объединить в похожие товары',
        'файл',
        'файлы',
        'документ',
        'документы',
        'ссылка',
        'ссылки',
        'link',
        'links',
    ];
    $out = [];
    foreach ($names as $name) {
        $key = supplier_push_norm_name((string)$name);
        if ($key !== '') {
            $out[$key] = true;
        }
    }
    return $out;
}

function supplier_push_ozon_rich_feature_lines(array $params, int $maxItems = 8): array
{
    $exclude = supplier_push_ozon_rich_param_exclusions();
    $out = [];
    $seen = [];
    foreach ($params as $name => $values) {
        $name = ft_sanitize_plain_text((string)$name);
        $nameKey = supplier_push_norm_name($name);
        if ($name === '' || $nameKey === '' || isset($exclude[$nameKey])) {
            continue;
        }
        $vals = [];
        foreach (supplier_push_split_attribute_values((array)$values) as $value) {
            $value = supplier_push_limit_text(ft_sanitize_plain_text((string)$value), 120);
            if ($value !== '') {
                $vals[] = $value;
            }
        }
        if (!$vals) {
            continue;
        }
        $joined = supplier_push_limit_text(implode(', ', array_slice(array_values(array_unique($vals)), 0, 3)), 150);
        if ($joined === '') {
            continue;
        }
        $line = supplier_push_limit_text($name . ': ' . $joined, 220);
        $key = supplier_push_lc($line);
        if ($line === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = '- ' . $line;
        if (count($out) >= $maxItems) {
            break;
        }
    }
    return $out;
}

function supplier_push_ozon_rich_image_node(string $url, string $alt = ''): ?array
{
    $url = trim((string)$url);
    if ($url === '') {
        return null;
    }
    $out = [
        'src' => $url,
        'srcMobile' => $url,
    ];
    $alt = supplier_push_limit_text(ft_sanitize_plain_text($alt), 120);
    if ($alt !== '') {
        $out['alt'] = $alt;
    }
    return $out;
}

function supplier_push_ozon_rich_plain_text_node(array $content, string $theme = 'default'): ?array
{
    $items = [];
    foreach ($content as $item) {
        $item = supplier_push_limit_text(ft_text_normalize_inline(ft_sanitize_plain_text((string)$item)), 320);
        if ($item !== '') {
            $items[] = $item;
        }
    }
    if (!$items) {
        return null;
    }
    return [
        'content' => array_values($items),
        'theme' => $theme,
    ];
}

function supplier_push_ozon_build_rich_content_json(array $bundle, array $cfg = []): string
{
    $title = supplier_push_limit_text(ft_text_normalize_inline(ft_sanitize_plain_text((string)($bundle['name'] ?? ''))), 160);
    $descriptionHtml = (string)(((array)($bundle['product'] ?? []))['description_html'] ?? ($bundle['description'] ?? ''));
    $pictures = supplier_push_clean_public_image_urls((array)($bundle['pictures'] ?? []), $cfg);
    $pictures = array_slice($pictures, 0, 8);
    $chunks = supplier_push_ozon_rich_text_chunks($descriptionHtml, 200);
    $features = supplier_push_ozon_rich_feature_lines((array)($bundle['params'] ?? []), 8);

    if (!$pictures && !$chunks && !$features) {
        return '';
    }

    $content = [];
    $leadText = $chunks[0] ?? '';
    $restText = array_slice($chunks, $leadText !== '' ? 1 : 0);
    if ($leadText === '' && $features) {
        $leadText = 'Ключевые характеристики и важные детали товара собраны ниже.';
    }

    if (count($pictures) >= 2) {
        $blocks = [];
        foreach (array_slice($pictures, 0, min(4, count($pictures))) as $index => $picture) {
            $imgNode = supplier_push_ozon_rich_image_node($picture, $title);
            if (!$imgNode) {
                continue;
            }
            $block = [
                'img' => $imgNode,
                'reverse' => ($index % 2) === 1,
            ];
            if ($index === 0 && $title !== '') {
                $block['title'] = $title;
            }
            if ($index === 0 && $leadText !== '') {
                $block['text'] = [
                    'content' => [$leadText],
                    'theme' => 'default',
                ];
            } elseif ($index === 1 && $restText) {
                $block['text'] = [
                    'content' => array_slice($restText, 0, 2),
                    'theme' => 'default',
                ];
            }
            $blocks[] = $block;
        }
        if (count($blocks) >= 2) {
            $content[] = [
                'widgetName' => 'raShowcase',
                'type' => 'chess',
                'blocks' => $blocks,
            ];
        }
    } elseif ($pictures) {
        $imgNode = supplier_push_ozon_rich_image_node($pictures[0], $title);
        if ($imgNode) {
            $block = ['img' => $imgNode];
            if ($title !== '') {
                $block['title'] = $title;
            }
            if ($leadText !== '') {
                $block['text'] = [
                    'content' => [$leadText],
                    'theme' => 'default',
                ];
            }
            $content[] = [
                'widgetName' => 'raShowcase',
                'type' => 'billboard',
                'blocks' => [$block],
            ];
        }
    }

    if ($restText || (!$pictures && $chunks)) {
        $textContent = !$pictures ? $chunks : $restText;
        foreach (array_chunk($textContent, 6) as $groupIndex => $groupLines) {
            $textBlock = [
                'widgetName' => 'raTextBlock',
                'gapSize' => 'm',
            ];
            $body = supplier_push_ozon_rich_plain_text_node($groupLines, 'default');
            if ($body) {
                $textBlock['text'] = $body;
            }
            if ($groupIndex === 0 && $title !== '') {
                $heading = supplier_push_ozon_rich_plain_text_node([$title], 'title');
                if ($heading) {
                    $textBlock['title'] = $heading;
                }
            }
            if (isset($textBlock['text']) || isset($textBlock['title'])) {
                $content[] = $textBlock;
            }
        }
    }

    if ($features) {
        $content[] = [
            'widgetName' => 'raTextBlock',
            'gapSize' => 'm',
            'title' => [
                'content' => ['Характеристики'],
                'theme' => 'title',
            ],
            'text' => [
                'content' => $features,
                'theme' => 'default',
            ],
        ];
    }

    if (!$content) {
        return '';
    }

    $json = json_encode(['content' => $content, 'version' => 0.2], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return '';
    }
    return ft_sanitize_ozon_rich_json(supplier_push_limit_text($json, 30000));
}

function supplier_push_should_send_ozon_rich_content(array $fields): bool
{
    return !empty($fields['description']);
}

function supplier_push_ozon_annotation_attribute_id(array $attrMeta): int
{
    $meta = supplier_push_find_meta_by_name($attrMeta, ['Аннотация', 'Описание', 'Описание товара']);
    $id = (int)($meta['id'] ?? 0);
    // Ozon global attribute: "Аннотация" / product marketing description.
    return $id > 0 ? $id : 4191;
}

function supplier_push_ozon_rich_content_attribute_id(array $attrMeta): int
{
    $meta = supplier_push_find_meta_by_name($attrMeta, ['Rich-контент JSON', 'Rich content JSON']);
    $id = (int)($meta['id'] ?? 0);
    // Ozon global attribute: "Rich-контент JSON".
    return $id > 0 ? $id : 11254;
}

function supplier_push_ozon_video_cover_attribute_id(array $attrMeta): int
{
    $meta = supplier_push_find_meta_by_name($attrMeta, [
        'Озон.Видеообложка: ссылка',
        'Ozon.VideoCover: link',
        'Видеообложка',
        'Видео-обложка',
    ]);
    $id = (int)($meta['id'] ?? 0);
    // Ozon global attribute: "Озон.Видеообложка: ссылка".
    return $id > 0 ? $id : 21845;
}

function supplier_push_ozon_video_cover_attribute(array $attrMeta, string $videoCoverUrl): ?array
{
    $attr = supplier_push_ozon_attribute(supplier_push_ozon_video_cover_attribute_id($attrMeta), [$videoCoverUrl]);
    if (!$attr) {
        return null;
    }
    $attr['complex_id'] = 100002;
    return $attr;
}

function supplier_push_selected_fields(array $params): array
{
    $raw = trim((string)($params['fields_json'] ?? ''));
    $allowed = [
        'name' => true,
        'model' => true,
        'photos' => true,
        'video' => true,
        'brand' => true,
        'dimensions' => true,
        'description' => true,
        'characteristics' => true,
        'tnved' => true,
    ];
    $fields = [];
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $field) {
                $field = trim((string)$field);
                if (isset($allowed[$field])) {
                    $fields[$field] = true;
                }
            }
        }
    }
    if (!$fields) {
        throw new RuntimeException('Выбери данные, которые нужно отправить.');
    }
    return $fields;
}

function supplier_push_all_content_fields(): array
{
    return [
        'name' => true,
        'model' => true,
        'photos' => true,
        'brand' => true,
        'dimensions' => true,
        'description' => true,
        'characteristics' => true,
        'tnved' => true,
    ];
}

function supplier_push_only_content_field(array $fields, string $fieldName): bool
{
    $enabled = [];
    foreach ($fields as $name => $active) {
        if (!empty($active)) {
            $enabled[] = (string)$name;
        }
    }
    return count($enabled) === 1 && $enabled[0] === $fieldName;
}

function supplier_push_all_content_fields_selected(array $fields): bool
{
    foreach (supplier_push_all_content_fields() as $name => $_active) {
        if (empty($fields[$name])) {
            return false;
        }
    }
    return true;
}

function supplier_push_enabled_field_names(array $fields): array
{
    $out = [];
    foreach ($fields as $name => $active) {
        if (!empty($active)) {
            $out[] = (string)$name;
        }
    }
    return $out;
}

function supplier_push_public_image_mirror_root(): string
{
    return dirname(__DIR__) . '/public/supplier-product-images';
}

function supplier_push_is_http_url(string $url): bool
{
    $parts = parse_url(trim($url));
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    $scheme = strtolower((string)$parts['scheme']);
    return $scheme === 'http' || $scheme === 'https';
}

function supplier_push_absolute_public_url(string $url, array $cfg = []): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (str_starts_with($url, '//')) {
        $base = supplier_products_public_base_url($cfg);
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $url;
    }
    if (supplier_push_is_http_url($url)) {
        return $url;
    }
    if (str_starts_with($url, '/')) {
        $base = supplier_products_public_base_url($cfg);
        return $base !== '' ? (rtrim($base, '/') . $url) : '';
    }
    return '';
}

function supplier_push_public_image_mirror_url(string $relativePath, array $cfg = []): string
{
    $base = supplier_products_public_base_url($cfg);
    if ($base === '') {
        return '';
    }
    $relativePath = str_replace('\\', '/', ltrim(trim($relativePath), '/'));
    if ($relativePath === '' || str_contains($relativePath, '../') || str_contains($relativePath, '..\\')) {
        return '';
    }
    $segments = array_map('rawurlencode', explode('/', $relativePath));
    return rtrim($base, '/') . '/supplier-product-images/' . implode('/', $segments);
}

function supplier_push_materialize_local_image_url(string $url, array $cfg = []): string
{
    return supplier_products_materialize_local_image_url($url, $cfg);
}

function supplier_push_prepare_public_image_url(string $url, array $cfg = []): string
{
    return supplier_products_prepare_public_picture_url($url, $cfg);
}

function supplier_push_clean_public_image_urls(array $urls, array $cfg = [], int $limit = 0): array
{
    return supplier_products_public_picture_urls($urls, $cfg, $limit);
}

function supplier_push_prepare_public_media_url(string $url, array $cfg = []): string
{
    return supplier_products_prepare_public_picture_url($url, $cfg);
}

function supplier_push_media_url_is_video(string $url): bool
{
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
    return (bool)preg_match('~\.(mp4|mov|m4v|webm)(?:$|\?)~i', $path);
}

function supplier_push_wb_current_photo_urls(array $card): array
{
    $urls = [];
    foreach (['photos', 'mediaFiles', 'images'] as $key) {
        foreach ((array)($card[$key] ?? []) as $item) {
            if (is_array($item)) {
                $url = trim((string)($item['big'] ?? ($item['c516x688'] ?? ($item['url'] ?? ($item['fullSize'] ?? '')))));
            } else {
                $url = trim((string)$item);
            }
            if ($url !== '' && supplier_push_is_http_url($url) && !supplier_push_media_url_is_video($url)) {
                $urls[] = $url;
            }
        }
        if ($urls) {
            break;
        }
    }
    return array_values(array_unique($urls));
}

function supplier_push_wb_media_urls_for_payload(array $bundle, array $cfg = [], bool $includePhotos = true, bool $includeVideo = true, array $currentCard = []): array
{
    $media = $includePhotos
        ? supplier_push_clean_public_image_urls((array)($bundle['pictures'] ?? []), $cfg, 30)
        : supplier_push_wb_current_photo_urls($currentCard);
    if ($includeVideo && ($videoCoverUrl = supplier_push_video_cover_url($bundle)) !== '') {
        $videoCoverUrl = supplier_push_prepare_public_media_url($videoCoverUrl, $cfg);
        if ($videoCoverUrl !== '' && supplier_push_is_http_url($videoCoverUrl)) {
            $seen = array_fill_keys($media, true);
            if (!isset($seen[$videoCoverUrl])) {
                $media[] = $videoCoverUrl;
            }
        }
    }
    return $media;
}

function supplier_push_ozon_image_url_needs_jpeg_mirror(string $url): bool
{
    $path = strtolower(rawurldecode((string)(parse_url($url, PHP_URL_PATH) ?? '')));
    if ($path === '') {
        return false;
    }
    return str_ends_with($path, '.webp')
        || str_ends_with($path, '.gif')
        || str_ends_with($path, '.avif')
        || str_contains($path, '/webp/');
}

function supplier_push_ozon_image_url_extension(string $url): string
{
    $path = strtolower(rawurldecode((string)(parse_url($url, PHP_URL_PATH) ?? '')));
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    return preg_replace('~[^a-z0-9]+~', '', $ext) ?? '';
}

function supplier_push_ozon_image_url_is_trusted_public(string $url, array $cfg = []): bool
{
    if (supplier_products_remote_image_relative_from_url($url, $cfg) !== '') {
        return true;
    }

    $host = strtolower(trim((string)(parse_url($url, PHP_URL_HOST) ?? '')));
    return $host !== '' && ($host === 'ozone.ru' || str_ends_with($host, '.ozone.ru') || $host === 'ozon.ru' || str_ends_with($host, '.ozon.ru'));
}

function supplier_push_ozon_image_url_should_jpeg_mirror(string $url, array $cfg = []): bool
{
    if (supplier_push_ozon_image_url_needs_jpeg_mirror($url)) {
        return true;
    }

    $ext = supplier_push_ozon_image_url_extension($url);
    if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        return false;
    }

    return supplier_push_is_http_url($url) && !supplier_push_ozon_image_url_is_trusted_public($url, $cfg);
}

function supplier_push_ozon_photo_format_label(string $url): string
{
    $ext = supplier_push_ozon_image_url_extension($url);
    return $ext !== '' ? strtoupper($ext) . '-фото' : 'фото';
}

function supplier_push_ozon_image_is_external_cache_mirror(string $url, array $cfg = []): bool
{
    $relative = supplier_products_remote_image_relative_from_url($url, $cfg);
    return $relative !== '' && str_starts_with(str_replace('\\', '/', $relative), 'ozon_photo_cache/');
}

function supplier_push_ozon_compact_fallback_images(array $pictures, array $cfg = []): array
{
    $pictures = supplier_push_ozon_normalized_images($pictures);
    if (!$pictures) {
        return [];
    }

    $out = [];
    $seen = [];
    $append = static function (string $url) use (&$out, &$seen): void {
        $url = trim($url);
        if ($url === '' || isset($seen[$url]) || !supplier_push_is_http_url($url)) {
            return;
        }
        $seen[$url] = true;
        $out[] = $url;
    };

    // Preserve the intended cover first, then keep generated/internal photos.
    $append((string)$pictures[0]);
    foreach (array_slice($pictures, 1) as $url) {
        $url = (string)$url;
        if (supplier_push_ozon_image_is_external_cache_mirror($url, $cfg)) {
            continue;
        }
        $append($url);
        if (count($out) >= 6) {
            break;
        }
    }

    return $out;
}

function supplier_push_ozon_minimal_fallback_images(array $pictures): array
{
    $pictures = supplier_push_ozon_normalized_images($pictures);
    return $pictures ? [(string)$pictures[0]] : [];
}

function supplier_push_ozon_same_image_list(array $a, array $b): bool
{
    return array_values($a) === array_values($b);
}

function supplier_push_ozon_photo_import_payload(int $productId, array $pictures): array
{
    return [
        'product_id' => $productId,
        'images' => array_values($pictures),
    ];
}

function supplier_push_ozon_jpeg_mirror_url(string $url, array $cfg = []): string
{
    static $cache = [];

    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $key = hash('sha256', $url);
    if (array_key_exists($key, $cache)) {
        return (string)$cache[$key];
    }

    $relative = 'ozon_photo_cache/' . substr($key, 0, 2) . '/' . $key . '.jpg';
    $targetPath = rtrim(supplier_products_image_storage_dir($cfg), '/\\') . '/' . $relative;
    $targetDir = dirname($targetPath);

    if (!is_file($targetPath) || (int)@filesize($targetPath) <= 0) {
        supplier_products_ensure_writable_dir($targetDir, 'Не удалось создать папку для JPEG-кэша фото Ozon.');
        $source = supplier_products_bundle_photo_source_path($url, $cfg);
        try {
            $image = supplier_products_bundle_photo_load((string)$source['path']);
            $width = imagesx($image);
            $height = imagesy($image);
            if ($width <= 0 || $height <= 0) {
                throw new RuntimeException('Некорректный размер изображения.');
            }

            $canvas = imagecreatetruecolor($width, $height);
            if (!$canvas instanceof GdImage) {
                throw new RuntimeException('Не удалось подготовить JPEG-изображение для Ozon.');
            }
            imagealphablending($canvas, true);
            imagesavealpha($canvas, false);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
            imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
            unset($image);

            if (!imagejpeg($canvas, $targetPath, 92)) {
                unset($canvas);
                throw new RuntimeException('Не удалось сохранить JPEG-версию фото для Ozon.');
            }
            unset($canvas);
            @chmod($targetPath, 0664);
        } finally {
            if (!empty($source['temporary'])) {
                @unlink((string)$source['path']);
            }
        }
    }

    $cache[$key] = supplier_products_publish_stored_image($targetPath, $relative, $cfg, true);
    return (string)$cache[$key];
}

function supplier_push_ozon_public_image_urls(array $urls, array $cfg = [], array &$stats = [], string $offerId = '', int $limit = 0): array
{
    $prepared = supplier_push_clean_public_image_urls($urls, $cfg, $limit > 0 ? max($limit * 2, $limit) : 0);
    $out = [];
    $seen = [];

    foreach ($prepared as $url) {
        $url = trim((string)$url);
        if ($url === '') {
            continue;
        }

        if (supplier_push_ozon_image_url_should_jpeg_mirror($url, $cfg)) {
            try {
                $mirrored = supplier_push_ozon_jpeg_mirror_url($url, $cfg);
                if ($mirrored !== '') {
                    if (supplier_push_ozon_image_url_needs_jpeg_mirror($url)) {
                        $stats['ozon_photo_webp_converted'] = (int)($stats['ozon_photo_webp_converted'] ?? 0) + 1;
                    } else {
                        $stats['ozon_photo_external_mirrored'] = (int)($stats['ozon_photo_external_mirrored'] ?? 0) + 1;
                    }
                    $url = $mirrored;
                }
            } catch (Throwable $e) {
                $stats['skipped'][] = 'Ozon photo: ' . supplier_push_ozon_photo_format_label($url)
                    . ($offerId !== '' ? ' для ' . $offerId : '')
                    . ' не удалось подготовить как JPEG: ' . $e->getMessage();
                continue;
            }
        }

        if ($url === '' || isset($seen[$url]) || !ft_looks_like_image_url($url)) {
            continue;
        }
        $seen[$url] = true;
        $out[] = $url;
        if ($limit > 0 && count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

function supplier_push_ozon_current_card_update_blocker(array $current): string
{
    $parts = [];
    $errors = $current['errors'] ?? [];
    if (!is_array($errors)) {
        $errors = [];
    }

    foreach ($errors as $error) {
        if (!is_array($error)) {
            continue;
        }
        $code = trim((string)($error['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $texts = is_array($error['texts'] ?? null) ? (array)$error['texts'] : [];
        $description = trim((string)($texts['description'] ?? ''));
        if ($code === 'SPU_ALREADY_EXISTS_IN_ANOTHER_ACCOUNT') {
            $parts[] = $description !== ''
                ? $description
                : 'карточка дублируется в другом кабинете Ozon';
        }
    }

    $statuses = is_array($current['statuses'] ?? null) ? (array)$current['statuses'] : [];
    $isCreated = array_key_exists('is_created', $statuses) ? (bool)$statuses['is_created'] : true;
    $statusDescription = trim((string)($statuses['status_description'] ?? ''));
    $statusTooltip = trim((string)($statuses['status_tooltip'] ?? ''));
    if (!$isCreated || mb_strtolower($statusDescription, 'UTF-8') === 'не создан') {
        $errorBits = [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            $texts = is_array($error['texts'] ?? null) ? (array)$error['texts'] : [];
            $attribute = trim((string)($texts['attribute_name'] ?? ''));
            $description = trim((string)($texts['description'] ?? ($texts['message'] ?? '')));
            $bit = trim($attribute . ($attribute !== '' && $description !== '' ? ' — ' : '') . $description);
            if ($bit !== '') {
                $errorBits[] = $bit;
            }
            if (count($errorBits) >= 2) {
                break;
            }
        }
        $details = $errorBits ? implode('; ', $errorBits) : ($statusTooltip !== '' ? $statusTooltip : 'не прошла валидацию');
        $parts[] = 'карточка Ozon не создана: ' . $details;
    }
    if ($statusDescription === 'Не обновлен' && $statusTooltip !== '') {
        $parts[] = $statusTooltip;
    }

    if (!$parts) {
        return '';
    }

    $out = [];
    $seen = [];
    foreach ($parts as $part) {
        $part = preg_replace('/\s+/u', ' ', trim((string)$part)) ?: '';
        if ($part === '' || isset($seen[$part])) {
            continue;
        }
        $seen[$part] = true;
        $out[] = $part;
    }
    return implode(' ', $out);
}

function supplier_push_wb_card_update_batch_size(): int
{
    return 1000;
}

function supplier_push_wb_card_create_batch_size(): int
{
    return 100;
}

function supplier_push_offer_ids_from_params(array $params): array
{
    $raw = [];
    foreach (['offer_ids', 'selected_offer_ids'] as $key) {
        if (!empty($params[$key]) && is_array($params[$key])) {
            foreach ($params[$key] as $value) {
                $raw[] = $value;
            }
        }
    }

    $json = trim((string)($params['offer_ids_json'] ?? ''));
    if ($json !== '') {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            foreach ($decoded as $value) {
                $raw[] = $value;
            }
        }
    }

    $out = [];
    $seen = [];
    foreach ($raw as $value) {
        $value = trim((string)$value);
        if ($value === '' || isset($seen[$value])) {
            continue;
        }
        $seen[$value] = true;
        $out[] = $value;
    }
    return $out;
}

function supplier_push_selected_offer_ids(array $params, string $supplierCode): array
{
    return supplier_products_selected_offer_id_set(supplier_push_offer_ids_from_params($params), $supplierCode);
}

function supplier_push_product_is_selected(array $product, array $selectedIds, string $supplierCode): bool
{
    if (!$selectedIds) {
        return true;
    }
    return supplier_products_existing_product_is_selected($product, $selectedIds, $supplierCode);
}

function supplier_push_load_bundles(int $supplierId, array $params): array
{
    supplier_products_tables_ensure();
    $supplier = suppliers_get($supplierId);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }
    $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
    $selectedIds = supplier_push_selected_offer_ids($params, $supplierCode);
    if (!$selectedIds) {
        throw new RuntimeException('Сначала выбери товары в таблице.');
    }

    $products = [];
    $productIds = [];
    $selectedOfferIds = array_keys($selectedIds);
    foreach (array_chunk($selectedOfferIds, 800) as $chunk) {
        $chunk = array_values(array_filter(array_map('strval', $chunk), static fn($value): bool => trim($value) !== ''));
        if (!$chunk) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("
            SELECT *
            FROM feedtools_supplier_products
            WHERE supplier_id = ?
              AND offer_id IN ({$placeholders})
            ORDER BY sort_order ASC, id ASC
        ");
        $st->execute(array_merge([$supplierId], $chunk));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!supplier_push_product_is_selected($row, $selectedIds, $supplierCode)) {
                continue;
            }
            $productId = (int)($row['id'] ?? 0);
            if ($productId <= 0 || isset($products[$productId])) {
                continue;
            }
            $products[$productId] = $row;
            $productIds[] = $productId;
        }
    }

    if (!$products) {
        throw new RuntimeException('Выбранные товары не найдены в DB-датасете.');
    }

    uasort($products, static function (array $a, array $b): int {
        $sortA = (int)($a['sort_order'] ?? 0);
        $sortB = (int)($b['sort_order'] ?? 0);
        if ($sortA !== $sortB) {
            return $sortA <=> $sortB;
        }
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });
    $productIds = array_values(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $products));

    $baseOfferIds = [];
    foreach ($products as $product) {
        $bundleInfo = bundle_offer_parse((string)($product['offer_id'] ?? ''));
        if (!empty($bundleInfo['is_bundle']) && !empty($bundleInfo['format_valid'])) {
            $baseOfferId = trim((string)($bundleInfo['base_offer_id'] ?? ''));
            if ($baseOfferId !== '') {
                $baseOfferIds[$baseOfferId] = true;
            }
        }
    }

    $baseProductsByOfferId = [];
    if ($baseOfferIds) {
        $baseIds = array_keys($baseOfferIds);
        $placeholders = implode(',', array_fill(0, count($baseIds), '?'));
        $bs = db()->prepare("
            SELECT *
            FROM feedtools_supplier_products
            WHERE supplier_id = ?
              AND offer_id IN ({$placeholders})
        ");
        $bs->execute(array_merge([$supplierId], $baseIds));
        while ($baseRow = $bs->fetch(PDO::FETCH_ASSOC)) {
            $baseOfferId = trim((string)($baseRow['offer_id'] ?? ''));
            if ($baseOfferId !== '') {
                $baseProductsByOfferId[$baseOfferId] = $baseRow;
            }
        }
    }

    $fieldsByProduct = [];
    if ($productIds) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $fs = db()->prepare("
            SELECT *
            FROM feedtools_supplier_product_fields
            WHERE product_id IN ({$placeholders})
            ORDER BY product_id ASC, sort_order ASC, id ASC
        ");
        $fs->execute($productIds);
        while ($field = $fs->fetch(PDO::FETCH_ASSOC)) {
            $productId = (int)($field['product_id'] ?? 0);
            if ($productId > 0) {
                $fieldsByProduct[$productId][] = $field;
            }
        }
    }

    $bundles = [];
    foreach ($products as $productId => $product) {
        $fields = (array)($fieldsByProduct[$productId] ?? []);
        $grouped = supplier_products_group_fields($fields);
        $standard = [];
        $tags = [];
        $paramsMap = [];
        $wbParamsMap = [];

        foreach ($grouped['standard'] as $field) {
            $name = trim((string)($field['field_name'] ?? ''));
            if ($name !== '') {
                $standard[$name] = trim((string)($field['field_value'] ?? ''));
            }
        }
        foreach ($grouped['tag'] as $field) {
            $name = trim((string)($field['field_name'] ?? ''));
            if ($name !== '') {
                $tags[$name] = trim((string)($field['field_value'] ?? ''));
            }
        }
        foreach ($grouped['param'] as $field) {
            $name = trim((string)($field['field_name'] ?? ''));
            $value = trim((string)($field['field_value'] ?? ''));
            if ($name !== '' && $value !== '') {
                $paramsMap[$name][] = $value;
            }
        }
        foreach ($grouped['wb_param'] as $field) {
            $name = trim((string)($field['field_name'] ?? ''));
            $value = trim((string)($field['field_value'] ?? ''));
            if ($name !== '' && $value !== '') {
                $wbParamsMap[$name][] = $value;
            }
        }
        $standardTnvedCode = supplier_push_tnved_code_for_payload((string)($standard[supplier_products_tnved_standard_field_name()] ?? ''));
        if ($standardTnvedCode !== '') {
            foreach (array_keys($paramsMap) as $name) {
                if (supplier_push_is_tnved_attribute_name((string)$name)) {
                    unset($paramsMap[$name]);
                }
            }
            foreach (array_keys($wbParamsMap) as $name) {
                if (supplier_push_is_tnved_attribute_name((string)$name)) {
                    unset($wbParamsMap[$name]);
                }
            }
            $paramsMap['ТН ВЭД коды ЕАЭС'] = [$standardTnvedCode];
            $wbParamsMap['Код ТН ВЭД'] = [$standardTnvedCode];
        }

        $brand = trim((string)($standard['brand'] ?? ''));
        if ($brand === '') {
            $brand = trim((string)($product['brand'] ?? ''));
        }
        $brandOzon = array_key_exists('brand_ozon', $standard) ? trim((string)$standard['brand_ozon']) : $brand;
        $brandWb = array_key_exists('brand_wb', $standard) ? trim((string)$standard['brand_wb']) : $brand;
        $bundleInfo = bundle_offer_parse((string)($product['offer_id'] ?? ''));
        $baseOfferId = trim((string)($bundleInfo['base_offer_id'] ?? ''));

        $bundles[] = [
            'product' => $product,
            'bundle_info' => $bundleInfo,
            'bundle_base_product' => is_array($baseProductsByOfferId[$baseOfferId] ?? null) ? $baseProductsByOfferId[$baseOfferId] : null,
            'fields' => $fields,
            'grouped' => $grouped,
            'standard' => $standard,
            'tags' => $tags,
            'params' => $paramsMap,
            'wb_params' => $wbParamsMap,
            'offer_id' => trim((string)($product['offer_id'] ?? '')),
            'vendor_code' => trim((string)($product['vendor_code'] ?? '')),
            'name' => trim((string)($product['name'] ?? '')),
            'model' => trim((string)($tags['same_model'] ?? '')),
            'brand' => $brand,
            'brand_ozon' => $brandOzon,
            'brand_wb' => $brandWb,
            'description' => supplier_push_bundle_description($product, $tags),
            'pictures' => supplier_products_normalize_picture_urls(
                supplier_products_decode_json_array($product['pictures_json'] ?? null)
            ),
        ];
    }

    return [$supplier, $bundles];
}

function supplier_push_first_price_number(array $bundle): ?float
{
    $product = (array)($bundle['product'] ?? []);
    $standard = (array)($bundle['standard'] ?? []);
    $tags = (array)($bundle['tags'] ?? []);
    $bundleInfo = (array)($bundle['bundle_info'] ?? []);
    $baseProduct = is_array($bundle['bundle_base_product'] ?? null) ? (array)$bundle['bundle_base_product'] : [];
    if (!empty($bundleInfo['is_bundle']) && $baseProduct) {
        $basePrice = supplier_push_parse_number((string)($baseProduct['price_original'] ?? ''));
        if ($basePrice !== null && $basePrice > 0) {
            return $basePrice * max(1, (int)($bundleInfo['bundle_qty'] ?? 1));
        }
    }

    $candidates = [
        $standard['purchase_price'] ?? '',
        $product['price_original'] ?? '',
        $tags['price_original'] ?? '',
        $tags['price'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $num = supplier_push_parse_number((string)$candidate);
        if ($num !== null && $num > 0) {
            return $num;
        }
    }
    return null;
}

function supplier_push_ozon_source_price(array $bundle): string
{
    $num = supplier_push_first_price_number($bundle);
    return $num !== null ? supplier_push_marketplace_price_rub_string($num * 2.5) : '';
}

function supplier_push_barcode(array $bundle): string
{
    $tags = (array)($bundle['tags'] ?? []);
    foreach (['barcode', 'barcodes', 'ean', 'gtin'] as $key) {
        $raw = trim((string)($tags[$key] ?? ''));
        if ($raw === '') {
            continue;
        }
        $parts = preg_split('/[\s,;]+/u', $raw);
        foreach ((array)$parts as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                return $part;
            }
        }
    }
    return '';
}

function supplier_push_parse_number(string $value): ?float
{
    $value = trim(str_replace(["\xc2\xa0", ' '], '', $value));
    $value = str_replace(',', '.', $value);
    if ($value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        if (preg_match('~[-+]?\d+(?:[\.,]\d+)?~u', $value, $m)) {
            $num = str_replace(',', '.', (string)$m[0]);
            return is_numeric($num) ? (float)$num : null;
        }
        return null;
    }
    return (float)$value;
}

function supplier_push_weight_kg(string $raw): ?float
{
    $raw = trim($raw);
    $n = supplier_push_parse_number($raw);
    if ($n === null || $n <= 0) {
        return null;
    }
    $lc = supplier_push_lc($raw);
    if (str_contains($lc, 'кг') || preg_match('~\bkg\b~u', $lc)) {
        return $n;
    }
    if (str_contains($lc, 'г') || preg_match('~\bg\b~u', $lc)) {
        return $n / 1000.0;
    }
    return $n > 30.0 ? ($n / 1000.0) : $n;
}

function supplier_push_weight_g(string $raw): ?int
{
    $kg = supplier_push_weight_kg($raw);
    if ($kg === null || $kg <= 0) {
        return null;
    }
    return max(1, (int)round($kg * 1000.0));
}

function supplier_push_dimension_unit_hint(string $raw): string
{
    $lc = supplier_push_lc($raw);
    if (str_contains($lc, 'мм') || preg_match('~\bmm\b~u', $lc)) {
        return 'mm';
    }
    if (str_contains($lc, 'см') || preg_match('~\bcm\b~u', $lc)) {
        return 'cm';
    }
    return '';
}

function supplier_push_dimension_triplet_from_text(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $norm = str_replace(',', '.', mb_strtolower($raw, 'UTF-8'));
    if (!preg_match('~(\d+(?:\.\d+)?)\s*(?:x|х|×|\*|/|на)\s*(\d+(?:\.\d+)?)\s*(?:x|х|×|\*|/|на)\s*(\d+(?:\.\d+)?)~u', $norm, $m)) {
        return [];
    }
    $values = [(float)$m[1], (float)$m[2], (float)$m[3]];
    foreach ($values as $value) {
        if ($value <= 0) {
            return [];
        }
    }
    return $values;
}

function supplier_push_dimension_triplet_scale_score(array $left, array $right, float $scale): int
{
    $left = array_values(array_filter($left, static fn($value): bool => is_numeric($value) && (float)$value > 0));
    $right = array_values(array_filter($right, static fn($value): bool => is_numeric($value) && (float)$value > 0));
    if (count($left) !== 3 || count($right) !== 3 || $scale <= 0) {
        return 0;
    }
    sort($left, SORT_NUMERIC);
    sort($right, SORT_NUMERIC);
    $score = 0;
    for ($i = 0; $i < 3; $i++) {
        $expected = (float)$right[$i] * $scale;
        $actual = (float)$left[$i];
        if ($expected <= 0 || $actual <= 0) {
            continue;
        }
        $diffRatio = abs($actual - $expected) / max($actual, $expected);
        if ($diffRatio <= 0.16 || abs($actual - $expected) <= 2.0) {
            $score += 2;
        } elseif ($diffRatio <= 0.32) {
            $score += 1;
        }
    }
    return $score;
}

function supplier_push_dimension_context_candidates(array $bundle): array
{
    $out = [];
    $add = static function (string $source, string $name, string $value) use (&$out): void {
        $name = trim($name);
        $value = trim($value);
        if ($value === '') {
            return;
        }
        $key = supplier_push_lc($name);
        $looksDimension = preg_match('~(?:sizeinpackage|dimension|dimensions|package\s*size|габарит|размер|упаков|длина|ширина|высота)~u', $key) === 1;
        if (!$looksDimension && supplier_push_dimension_unit_hint($value) === '' && !supplier_push_dimension_triplet_from_text($value)) {
            return;
        }
        $out[] = [
            'source' => $source,
            'name' => $name,
            'value' => $value,
            'name_key' => $key,
        ];
    };

    foreach ((array)($bundle['tags'] ?? []) as $name => $value) {
        $add('tag', (string)$name, (string)$value);
    }
    foreach ((array)($bundle['params'] ?? []) as $name => $values) {
        foreach ((array)$values as $value) {
            $add('param', (string)$name, (string)$value);
        }
    }
    foreach ((array)($bundle['wb_params'] ?? []) as $name => $values) {
        foreach ((array)$values as $value) {
            $add('wb_param', (string)$name, (string)$value);
        }
    }

    return $out;
}

function supplier_push_dimension_unit_from_context(array $bundle, array $nums, string $rawJoin): string
{
    $hint = supplier_push_dimension_unit_hint($rawJoin);
    if ($hint !== '') {
        return $hint;
    }

    $standardNums = array_values(array_filter($nums, static fn($value): bool => $value !== null && $value > 0));
    if (count($standardNums) !== 3) {
        return '';
    }

    foreach (supplier_push_dimension_context_candidates($bundle) as $candidate) {
        $value = (string)($candidate['value'] ?? '');
        $triplet = supplier_push_dimension_triplet_from_text($value);
        if (!$triplet) {
            continue;
        }
        $candidateHint = supplier_push_dimension_unit_hint($value);
        $nameKey = (string)($candidate['name_key'] ?? '');
        $looksPackageTriplet = preg_match('~(?:sizeinpackage|dimension|габарит|размер|упаков)~u', $nameKey) === 1;

        if ($candidateHint === 'cm') {
            if (supplier_push_dimension_triplet_scale_score($standardNums, $triplet, 10.0) >= 4) {
                return 'mm';
            }
            if (supplier_push_dimension_triplet_scale_score($standardNums, $triplet, 1.0) >= 4) {
                return 'cm';
            }
        } elseif ($candidateHint === 'mm') {
            if (supplier_push_dimension_triplet_scale_score($standardNums, $triplet, 1.0) >= 4) {
                return 'mm';
            }
            if (supplier_push_dimension_triplet_scale_score($standardNums, $triplet, 0.1) >= 4) {
                return 'cm';
            }
        } elseif ($looksPackageTriplet) {
            if (supplier_push_dimension_triplet_scale_score($standardNums, $triplet, 10.0) >= 4) {
                return 'mm';
            }
            if (supplier_push_dimension_triplet_scale_score($standardNums, $triplet, 1.0) >= 4) {
                return 'cm';
            }
        }
    }

    return '';
}

function supplier_push_dimensions(array $bundle): array
{
    $standard = (array)($bundle['standard'] ?? []);
    $rawParts = [
        trim((string)($standard['length'] ?? '')),
        trim((string)($standard['width'] ?? '')),
        trim((string)($standard['height'] ?? '')),
    ];
    $rawJoin = implode(' ', $rawParts);
    $nums = [];
    foreach ($rawParts as $part) {
        $nums[] = supplier_push_parse_number($part);
    }
    if ($nums[0] === null && $nums[1] === null && $nums[2] === null) {
        return ['mm' => null, 'cm' => null];
    }
    $positive = array_values(array_filter($nums, static fn($v) => $v !== null && $v > 0));
    if (!$positive) {
        return ['mm' => null, 'cm' => null];
    }
    $hint = supplier_push_dimension_unit_from_context($bundle, $nums, $rawJoin);
    $asCm = $hint === 'cm';
    if ($hint === '') {
        // Standard length/width/height values in supplier products are stored as cm.
        // Very large unlabeled values are usually already millimeters from raw feeds.
        $asCm = max($positive) <= 300.0;
    }
    $mm = [];
    $cm = [];
    foreach ($nums as $n) {
        if ($n === null || $n <= 0) {
            $mm[] = null;
            $cm[] = null;
            continue;
        }
        $mmValue = $asCm ? ($n * 10.0) : $n;
        $cmValue = $mmValue / 10.0;
        $mm[] = (int)round($mmValue);
        $cm[] = round($cmValue, 3);
    }
    return ['mm' => $mm, 'cm' => $cm];
}

function supplier_push_taxonomy_meta(string $source, string $categoryValue): array
{
    $categoryValue = trim($categoryValue);
    if ($categoryValue === '') {
        return [];
    }
    static $cache = [];
    $cacheKey = $source . '|' . $categoryValue;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if ($source === 'ozon') {
        if (!preg_match('~^(\d+)_([0-9]+)$~', $categoryValue, $m)) {
            return $cache[$cacheKey] = [];
        }
        $st = db()->prepare("
            SELECT *
            FROM feedtools_taxonomy_categories
            WHERE source = 'ozon'
              AND is_leaf = 1
              AND ozon_parent_id = ?
              AND ozon_leaf_id = ?
            LIMIT 1
        ");
        $st->execute([(int)$m[1], (int)$m[2]]);
    } elseif ($source === 'wildberries') {
        $subjectId = (int)$categoryValue;
        if ($subjectId <= 0) {
            return $cache[$cacheKey] = [];
        }
        $st = db()->prepare("
            SELECT *
            FROM feedtools_taxonomy_categories
            WHERE source = 'wildberries'
              AND is_leaf = 1
              AND external_id = ?
            LIMIT 1
        ");
        $st->execute(['wb:subject:' . $subjectId]);
    } else {
        return $cache[$cacheKey] = [];
    }

    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return $cache[$cacheKey] = [];
    }
    $meta = json_decode((string)($row['meta_json'] ?? '{}'), true);
    if (!is_array($meta)) {
        $meta = [];
    }
    $meta['_row'] = $row;
    return $cache[$cacheKey] = $meta;
}

function supplier_push_find_meta_by_name(array $metaMap, array $names): ?array
{
    $exact = [];
    $normalized = [];
    foreach ($names as $name) {
        $nameNorm = supplier_push_norm_name((string)$name);
        if ($nameNorm !== '') {
            $exact[$nameNorm] = true;
        }
        foreach (supplier_push_attribute_alias_names((string)$name) as $candidate) {
            $candidateNorm = supplier_push_norm_name($candidate);
            if ($candidateNorm !== '') {
                $normalized[$candidateNorm] = true;
            }
        }
    }
    if (!$exact && !$normalized) {
        return null;
    }

    foreach ($metaMap as $key => $meta) {
        if (!is_array($meta)) {
            continue;
        }
        $candidates = [
            supplier_push_norm_name((string)$key),
            supplier_push_norm_name((string)($meta['name'] ?? '')),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && isset($exact[$candidate])) {
                return $meta;
            }
        }
    }

    foreach ($metaMap as $key => $meta) {
        if (!is_array($meta)) {
            continue;
        }
        $candidates = [
            supplier_push_norm_name((string)$key),
            supplier_push_norm_name((string)($meta['name'] ?? '')),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && isset($normalized[$candidate])) {
                return $meta;
            }
        }
    }
    return null;
}

function supplier_push_find_all_meta_by_name(array $metaMap, array $names): array
{
    $exact = [];
    $normalized = [];
    foreach ($names as $name) {
        $nameNorm = supplier_push_norm_name((string)$name);
        if ($nameNorm !== '') {
            $exact[$nameNorm] = true;
        }
        foreach (supplier_push_attribute_alias_names((string)$name) as $candidate) {
            $candidateNorm = supplier_push_norm_name($candidate);
            if ($candidateNorm !== '') {
                $normalized[$candidateNorm] = true;
            }
        }
    }
    if (!$exact && !$normalized) {
        return [];
    }

    $collect = static function (array $needles) use ($metaMap): array {
        $out = [];
        $seen = [];
        foreach ($metaMap as $key => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $candidates = [
                supplier_push_norm_name((string)$key),
                supplier_push_norm_name((string)($meta['name'] ?? '')),
            ];
            foreach ($candidates as $candidate) {
                if ($candidate === '' || !isset($needles[$candidate])) {
                    continue;
                }
                $id = (int)($meta['id'] ?? 0);
                $seenKey = $id > 0 ? ('id:' . $id) : ('name:' . $candidate);
                if (!isset($seen[$seenKey])) {
                    $seen[$seenKey] = true;
                    $out[] = $meta;
                }
                break;
            }
        }
        return $out;
    };

    $matches = $collect($exact);
    return $matches ?: $collect($normalized);
}

function supplier_push_ozon_meta_complex_id(array $meta): int
{
    return (int)($meta['attribute_complex_id'] ?? ($meta['complex_id'] ?? 0));
}

function supplier_push_ozon_attribute(int $id, array $values, int $complexId = 0): ?array
{
    $clean = [];
    $seen = [];
    foreach ($values as $value) {
        $dictionaryValueId = 0;
        if (is_array($value)) {
            $dictionaryValueId = (int)($value['dictionary_value_id'] ?? ($value['id'] ?? 0));
            $value = trim((string)($value['value'] ?? ($value['name'] ?? '')));
        } else {
            $value = trim((string)$value);
        }
        $seenKey = $dictionaryValueId > 0 ? ('id:' . $dictionaryValueId) : ('value:' . supplier_push_norm_name($value));
        if ($value === '' || isset($seen[$seenKey])) {
            continue;
        }
        $seen[$seenKey] = true;
        $clean[] = [
            'dictionary_value_id' => $dictionaryValueId,
            'value' => $value,
        ];
    }
    if ($id <= 0 || !$clean) {
        return null;
    }
    return [
        'complex_id' => max(0, $complexId),
        'id' => $id,
        'values' => $clean,
    ];
}

function supplier_push_ozon_tnved_code_from_text(string $value): string
{
    if (preg_match('~(?<!\d)(\d{10})(?!\d)~', $value, $m)) {
        return (string)$m[1];
    }
    return '';
}

function supplier_push_ozon_tnved_description_from_text(string $value): string
{
    $value = supplier_push_norm_spaces($value);
    if ($value === '') {
        return '';
    }
    $value = preg_replace('~^\d{10}\s*[-–—]?\s*~u', '', $value);
    $value = preg_replace('~^[-–—]\s*~u', '', (string)$value);
    return supplier_push_norm_spaces((string)$value);
}

function supplier_push_is_tnved_attribute_name(string $name): bool
{
    if (function_exists('supplier_products_is_tnved_characteristic_name')
        && supplier_products_is_tnved_characteristic_name($name)
    ) {
        return true;
    }
    $norm = supplier_push_norm_name($name);
    return in_array($norm, [
        'тн вэд',
        'тн вэд коды еаэс',
        'тнвэд',
        'код тн вэд',
        'код тнвэд',
        'tn ved',
        'tnved',
        'tnved code',
    ], true);
}

function supplier_push_tnved_code_for_payload(string $value): string
{
    $value = supplier_push_norm_spaces($value);
    if ($value === '') {
        return '';
    }
    $code = supplier_push_ozon_tnved_code_from_text($value);
    if ($code !== '') {
        return $code;
    }
    $digits = preg_replace('~\D+~u', '', $value);
    return is_string($digits) && preg_match('~^\d{10}$~', $digits) ? $digits : '';
}

function supplier_push_tnved_values_for_wb_payload(array $values): array
{
    $out = [];
    $seen = [];
    foreach (supplier_push_split_attribute_values($values) as $value) {
        $code = supplier_push_tnved_code_for_payload((string)$value);
        if ($code === '' || isset($seen[$code])) {
            continue;
        }
        $seen[$code] = true;
        $out[] = $code;
    }
    return $out;
}

function supplier_push_ozon_allowed_value_for_input(string $value, array $meta): string
{
    $value = supplier_push_norm_spaces($value);
    if ($value === '') {
        return '';
    }
    $allowedValues = function_exists('supplier_products_characteristic_allowed_values_from_meta_row')
        ? supplier_products_characteristic_allowed_values_from_meta_row($meta)
        : array_values((array)($meta['allowed_values'] ?? []));
    if (!$allowedValues) {
        return $value;
    }

    $valueNorm = supplier_push_norm_name($value);
    $valueCode = supplier_push_ozon_tnved_code_from_text($value);
    $valueDescNorm = supplier_push_norm_name(supplier_push_ozon_tnved_description_from_text($value));
    $isTnved = function_exists('supplier_products_is_tnved_characteristic_name')
        && supplier_products_is_tnved_characteristic_name((string)($meta['name'] ?? ''));
    if (supplier_push_ozon_is_release_type_meta($meta)
        && function_exists('supplier_products_ozon_release_type_value_for_input')) {
        $releaseTypeValue = supplier_products_ozon_release_type_value_for_input($value, $allowedValues);
        if ($releaseTypeValue !== '') {
            return $releaseTypeValue;
        }
    }

    foreach ($allowedValues as $allowed) {
        if (is_array($allowed)) {
            $allowed = (string)($allowed['value'] ?? ($allowed['name'] ?? ''));
        }
        $allowed = supplier_push_norm_spaces((string)$allowed);
        if ($allowed === '') {
            continue;
        }
        if (supplier_push_norm_name($allowed) === $valueNorm) {
            return $allowed;
        }
        if ($isTnved) {
            $allowedCode = supplier_push_ozon_tnved_code_from_text($allowed);
            if ($valueCode !== '' && $allowedCode === $valueCode) {
                return $allowed;
            }
            if ($valueCode === '' && $valueDescNorm !== '') {
                $allowedDescNorm = supplier_push_norm_name(supplier_push_ozon_tnved_description_from_text($allowed));
                if ($allowedDescNorm !== '' && $allowedDescNorm === $valueDescNorm) {
                    return $allowed;
                }
            }
        }
    }

    return $value;
}

function supplier_push_ozon_dictionary_value_search(
    array $oz,
    int $descriptionCategoryId,
    int $typeId,
    int $attributeId,
    string $query
): array {
    $query = supplier_push_norm_spaces($query);
    if ($query === '' || $descriptionCategoryId <= 0 || $typeId <= 0 || $attributeId <= 0) {
        return [];
    }

    static $cache = [];
    $cacheKey = $descriptionCategoryId . ':' . $typeId . ':' . $attributeId . ':' . supplier_push_norm_name($query);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $resp = ozon_post_json($oz, '/v1/description-category/attribute/values/search', [
            'description_category_id' => $descriptionCategoryId,
            'type_id' => $typeId,
            'attribute_id' => $attributeId,
            'value' => $query,
            'language' => 'RU',
            'limit' => 20,
        ]);
    } catch (Throwable $e) {
        return $cache[$cacheKey] = [];
    }

    $result = $resp['result'] ?? [];
    if (!is_array($result)) {
        $result = [];
    }
    $items = (isset($result['values']) && is_array($result['values'])) ? $result['values'] : $result;
    $out = [];
    foreach ((array)$items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int)($item['id'] ?? 0);
        $value = supplier_push_norm_spaces((string)($item['value'] ?? ($item['name'] ?? '')));
        if ($id > 0 && $value !== '') {
            $out[] = ['dictionary_value_id' => $id, 'value' => $value];
        }
    }
    return $cache[$cacheKey] = $out;
}

function supplier_push_ozon_live_attribute_meta(array $oz, int $descriptionCategoryId, int $typeId): array
{
    if ($descriptionCategoryId <= 0 || $typeId <= 0) {
        return [];
    }

    static $cache = [];
    $cacheKey = $descriptionCategoryId . ':' . $typeId;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $resp = ozon_post_json($oz, '/v1/description-category/attribute', [
            'description_category_id' => $descriptionCategoryId,
            'type_id' => $typeId,
        ]);
    } catch (Throwable $e) {
        return $cache[$cacheKey] = [];
    }

    $items = $resp['result'] ?? [];
    if (!is_array($items)) {
        return $cache[$cacheKey] = [];
    }
    if (isset($items['attributes']) && is_array($items['attributes'])) {
        $items = $items['attributes'];
    }

    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int)($item['id'] ?? ($item['attribute_id'] ?? 0));
        $name = trim((string)($item['name'] ?? ($item['attribute_name'] ?? '')));
        if ($id <= 0 || $name === '') {
            continue;
        }
        $out[$id] = [
            'id' => $id,
            'name' => $name,
            'description' => trim((string)($item['description'] ?? '')),
            'attribute_complex_id' => (int)($item['attribute_complex_id'] ?? ($item['complex_id'] ?? 0)),
            'required' => !empty($item['is_required']) || !empty($item['required']),
            'dictionary_id' => (int)($item['dictionary_id'] ?? 0),
            'type' => trim((string)($item['type'] ?? '')),
            'is_collection' => !empty($item['is_collection']),
            'max_count' => (int)($item['max_value_count'] ?? ($item['maxValueCount'] ?? ($item['max_count'] ?? ($item['maxCount'] ?? 0)))),
        ];
    }

    return $cache[$cacheKey] = $out;
}

function supplier_push_ozon_enrich_attr_meta_with_live_complex_ids(array $attrMeta, array $taxonomyMeta, ?array $oz): array
{
    if (!is_array($oz) || !$oz) {
        return $attrMeta;
    }

    $needsLive = false;
    foreach ($attrMeta as $meta) {
        if (is_array($meta) && (int)($meta['id'] ?? 0) > 0 && supplier_push_ozon_meta_complex_id($meta) <= 0) {
            $needsLive = true;
            break;
        }
    }
    if (!$needsLive) {
        return $attrMeta;
    }

    $descriptionCategoryId = (int)($taxonomyMeta['ozon_description_category_id'] ?? 0);
    $typeId = (int)($taxonomyMeta['ozon_type_id'] ?? 0);
    $live = supplier_push_ozon_live_attribute_meta($oz, $descriptionCategoryId, $typeId);
    if (!$live) {
        return $attrMeta;
    }

    $liveByNorm = [];
    foreach ($live as $id => $row) {
        $key = supplier_push_norm_name((string)($row['name'] ?? ''));
        if ($key !== '') {
            $liveByNorm[$key] = $row;
        }
    }

    foreach ($attrMeta as $key => $meta) {
        if (!is_array($meta)) {
            continue;
        }
        $id = (int)($meta['id'] ?? 0);
        $nameKey = supplier_push_norm_name((string)($meta['name'] ?? $key));
        $liveRow = ($id > 0 && isset($live[$id])) ? $live[$id] : ($liveByNorm[$nameKey] ?? null);
        if (!is_array($liveRow)) {
            continue;
        }
        foreach (['attribute_complex_id', 'dictionary_id', 'type', 'description', 'required', 'is_collection', 'max_count'] as $field) {
            if (($field === 'description' || $field === 'type') && trim((string)($meta[$field] ?? '')) !== '') {
                continue;
            }
            if (($field === 'dictionary_id' || $field === 'attribute_complex_id') && (int)($meta[$field] ?? 0) > 0) {
                continue;
            }
            if (array_key_exists($field, $liveRow)) {
                $meta[$field] = $liveRow[$field];
            }
        }
        $attrMeta[$key] = $meta;
    }

    return $attrMeta;
}

function supplier_push_ozon_resolve_dictionary_value(
    array $oz,
    int $descriptionCategoryId,
    int $typeId,
    int $attributeId,
    array $meta,
    string $value
): ?array {
    $value = supplier_push_norm_spaces($value);
    if ($value === '') {
        return null;
    }

    $allowedValue = supplier_push_ozon_allowed_value_for_input($value, $meta);
    $isCompatibleModel = supplier_push_ozon_is_compatible_models_meta($meta);
    $queries = [];
    foreach ([$allowedValue, $value, supplier_push_ozon_tnved_code_from_text($allowedValue), supplier_push_ozon_tnved_description_from_text($allowedValue)] as $query) {
        $query = supplier_push_norm_spaces((string)$query);
        if ($query !== '') {
            $queries[] = $query;
        }
    }
    if ($isCompatibleModel) {
        foreach (supplier_push_ozon_compatible_model_query_candidates($allowedValue !== '' ? $allowedValue : $value) as $query) {
            if ($query !== '') {
                $queries[] = $query;
            }
        }
    }
    $queries = array_values(array_unique($queries));

    $wantedNorms = [supplier_push_norm_name($allowedValue !== '' ? $allowedValue : $value)];
    if ($isCompatibleModel) {
        foreach (supplier_push_ozon_compatible_model_query_candidates($allowedValue !== '' ? $allowedValue : $value) as $candidate) {
            $candidateNorm = supplier_push_norm_name($candidate);
            if ($candidateNorm !== '') {
                $wantedNorms[] = $candidateNorm;
            }
        }
    }
    $wantedNorms = array_values(array_unique(array_filter($wantedNorms, static fn(string $v): bool => $v !== '')));
    $wantedCode = supplier_push_ozon_tnved_code_from_text($allowedValue !== '' ? $allowedValue : $value);
    $wantedDescNorm = supplier_push_norm_name(supplier_push_ozon_tnved_description_from_text($allowedValue !== '' ? $allowedValue : $value));
    foreach ($queries as $query) {
        foreach (supplier_push_ozon_dictionary_value_search($oz, $descriptionCategoryId, $typeId, $attributeId, $query) as $item) {
            $itemValue = (string)($item['value'] ?? '');
            $itemNorm = supplier_push_norm_name($itemValue);
            if (in_array($itemNorm, $wantedNorms, true)) {
                return $item;
            }
            if ($isCompatibleModel && supplier_push_ozon_compatible_model_matches($itemValue, $wantedNorms)) {
                return $item;
            }
            if ($wantedCode !== '' && supplier_push_ozon_tnved_code_from_text($itemValue) === $wantedCode) {
                return $item;
            }
            if ($wantedCode === '' && $wantedDescNorm !== '') {
                $itemDescNorm = supplier_push_norm_name(supplier_push_ozon_tnved_description_from_text($itemValue));
                if ($itemDescNorm !== '' && $itemDescNorm === $wantedDescNorm) {
                    return $item;
                }
            }
        }
    }

    return null;
}

function supplier_push_ozon_compatible_model_query_candidates(string $value): array
{
    $value = supplier_push_norm_spaces($value);
    if ($value === '') {
        return [];
    }

    $variants = [$value];
    $withoutBrackets = supplier_push_norm_spaces((string)preg_replace('~\([^)]*\)|\[[^]]*\]~u', ' ', $value));
    if ($withoutBrackets !== '') {
        $variants[] = $withoutBrackets;
    }

    foreach ($variants as $variant) {
        $cut = supplier_push_norm_spaces((string)preg_replace(
            '~\s+(?:с\s+комп(?:лектом|\.)?|с\s+тач(?:скрином)?|в\s+рамке|без\s+рамки|черн(?:ый|ая|ое)?|бел(?:ый|ая|ое)?|сер(?:ый|ая|ое)?|голуб(?:ой|ая|ое)?)\b.*$~iu',
            '',
            $variant
        ));
        if ($cut !== '') {
            $variants[] = $cut;
        }
    }

    $brandAliases = array_keys(supplier_push_ozon_fits_for_brand_aliases());
    $brandAliases = array_merge($brandAliases, array_values(supplier_push_ozon_fits_for_brand_aliases()));
    $brandPatternParts = [];
    foreach ($brandAliases as $brand) {
        $brand = trim((string)$brand);
        if ($brand !== '') {
            $brandPatternParts[] = preg_quote($brand, '~');
        }
    }
    $brandPattern = $brandPatternParts ? '~^(?:' . implode('|', array_unique($brandPatternParts)) . ')\s+~iu' : '';

    $out = [];
    foreach ($variants as $variant) {
        $variant = supplier_push_norm_spaces((string)$variant);
        if ($variant === '') {
            continue;
        }
        $out[] = $variant;

        $withoutTech = supplier_push_norm_spaces((string)preg_replace(
            '~\b(?:2G|3G|4G|5G|LTE|NFC|Wi[-\s]?Fi|Dual\s*SIM|DS)\b~iu',
            ' ',
            $variant
        ));
        if ($withoutTech !== '') {
            $out[] = $withoutTech;
        }

        $brandless = $brandPattern !== ''
            ? supplier_push_norm_spaces((string)preg_replace($brandPattern, '', $withoutTech))
            : $withoutTech;
        if ($brandless !== '') {
            $out[] = $brandless;
        }

        $withoutModelCode = supplier_push_norm_spaces((string)preg_replace(
            '~^(?:[A-ZА-Я]{1,4}\d{2,5}[A-ZА-Я0-9-]*|SM-[A-Z0-9-]+|RMX\d+|CPH\d+)\s+~iu',
            '',
            $brandless
        ));
        if ($withoutModelCode !== '') {
            $out[] = $withoutModelCode;
        }

        if (preg_match('~\b(Galaxy\s+[A-ZА-Я]?\d+[A-ZА-Я0-9]*(?:\s+(?:Plus|Ultra|Pro|Max|FE|Mini|Lite))?)\b~iu', $withoutModelCode, $m)) {
            $out[] = supplier_push_norm_spaces((string)$m[1]);
        }
    }

    $seen = [];
    $clean = [];
    foreach ($out as $candidate) {
        $candidate = supplier_push_norm_spaces((string)$candidate);
        $norm = supplier_push_norm_name($candidate);
        if ($candidate === '' || isset($seen[$norm])) {
            continue;
        }
        $seen[$norm] = true;
        $clean[] = $candidate;
    }
    return $clean;
}

function supplier_push_ozon_compatible_model_matches(string $itemValue, array $wantedNorms): bool
{
    $itemNorm = supplier_push_norm_name($itemValue);
    if ($itemNorm === '') {
        return false;
    }
    foreach ($wantedNorms as $wantedNorm) {
        $wantedNorm = supplier_push_norm_name((string)$wantedNorm);
        if ($wantedNorm === '') {
            continue;
        }
        if ($itemNorm === $wantedNorm) {
            return true;
        }
        $tokenCount = count(preg_split('~\s+~u', $wantedNorm, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($tokenCount >= 2 && str_ends_with($itemNorm, ' ' . $wantedNorm)) {
            return true;
        }
    }
    return false;
}

function supplier_push_ozon_dictionary_values_for_payload(
    array $values,
    array $meta,
    array $taxonomyMeta,
    ?array $oz,
    array &$skipped
): array {
    $attributeId = (int)($meta['id'] ?? ($meta['attribute_id'] ?? 0));
    $dictionaryId = (int)($meta['dictionary_id'] ?? 0);
    $isTnved = function_exists('supplier_products_is_tnved_characteristic_name')
        && supplier_products_is_tnved_characteristic_name((string)($meta['name'] ?? ''));
    $isStrictDictionary = supplier_push_ozon_is_fits_for_meta($meta)
        || supplier_push_ozon_is_compatible_models_meta($meta)
        || supplier_push_ozon_is_color_name_single_meta($meta)
        || supplier_push_ozon_is_release_type_meta($meta);
    if ($attributeId <= 0 || $dictionaryId <= 0 || !is_array($oz) || !$oz) {
        if ($isTnved) {
            $skipped[] = 'ТН ВЭД не отправлен: не удалось получить справочник Ozon для категории.';
            return [];
        }
        if ($isStrictDictionary) {
            $skipped[] = (string)($meta['name'] ?? 'Атрибут') . ' не отправлен: не удалось получить справочник Ozon для категории.';
            return [];
        }
        return $values;
    }

    $descriptionCategoryId = (int)($taxonomyMeta['ozon_description_category_id'] ?? 0);
    $typeId = (int)($taxonomyMeta['ozon_type_id'] ?? 0);
    if ($descriptionCategoryId <= 0 || $typeId <= 0) {
        if ($isTnved) {
            $skipped[] = 'ТН ВЭД не отправлен: у категории Ozon нет description_category_id/type_id для поиска значения справочника.';
            return [];
        }
        if ($isStrictDictionary) {
            $skipped[] = (string)($meta['name'] ?? 'Атрибут') . ' не отправлен: у категории Ozon нет description_category_id/type_id для поиска значения справочника.';
            return [];
        }
        return $values;
    }

    $out = [];
    foreach ($values as $value) {
        if (is_array($value) && (int)($value['dictionary_value_id'] ?? ($value['id'] ?? 0)) > 0) {
            $out[] = $value;
            continue;
        }
        $text = is_array($value) ? (string)($value['value'] ?? ($value['name'] ?? '')) : (string)$value;
        $resolved = supplier_push_ozon_resolve_dictionary_value($oz, $descriptionCategoryId, $typeId, $attributeId, $meta, $text);
        if ($resolved) {
            $out[] = $resolved;
            continue;
        }
        if ($isTnved) {
            $skipped[] = 'ТН ВЭД "' . $text . '" не найден в справочнике Ozon для категории; значение не отправлено';
            continue;
        }
        if ($isStrictDictionary) {
            $skipped[] = (string)($meta['name'] ?? 'Атрибут') . ' "' . $text . '" не найден в справочнике Ozon для категории; значение не отправлено';
            continue;
        }
        $out[] = $value;
    }
    return $out;
}

function supplier_push_ozon_payload_should_include_model(array $fields): bool
{
    return !empty($fields['model']);
}

function supplier_push_ozon_model_attribute(array $bundle, array $attrMeta): ?array
{
    $model = supplier_push_bundle_model_value($bundle);
    if ($model === '') {
        return null;
    }

    $modelMeta = supplier_push_find_meta_by_name($attrMeta, [
        'Название модели',
        'Название модели (для объединения в одну карточку)',
        'Название модели для объединения в одну карточку',
        'Название группы',
    ]);
    $id = (int)($modelMeta['id'] ?? 9048);
    return supplier_push_ozon_attribute($id, [$model]);
}

function supplier_push_marketplace_attribute_values(array $values, array $meta, string $source): array
{
    if ($source === 'ozon' && supplier_push_ozon_is_color_name_single_meta($meta)) {
        $values = supplier_push_first_attribute_value($values);
    }
    $allowedValues = [];
    if (function_exists('supplier_products_characteristic_allowed_values_from_meta_row')) {
        $allowedValues = supplier_products_characteristic_allowed_values_from_meta_row($meta);
    } elseif (is_array($meta['allowed_values'] ?? null)) {
        $allowedValues = array_values((array)$meta['allowed_values']);
    }
    $name = trim((string)($meta['name'] ?? ''));
    $restrict = function_exists('supplier_products_characteristic_should_restrict_to_allowed')
        ? supplier_products_characteristic_should_restrict_to_allowed($source, $name)
        : false;
    if (function_exists('supplier_products_normalize_characteristic_values')) {
        return supplier_products_normalize_characteristic_values($values, $allowedValues, $restrict);
    }
    return supplier_push_split_attribute_values($values);
}

function supplier_push_ozon_prepare_attribute_values_for_payload(
    array $values,
    array $meta,
    array $bundle,
    array $taxonomyMeta,
    array &$skipped
): array {
    if (supplier_push_lc(trim((string)($meta['type'] ?? ''))) === 'boolean') {
        foreach (supplier_push_split_attribute_values($values) as $value) {
            $booleanValue = supplier_push_ozon_boolean_value($value);
            if ($booleanValue !== '') {
                return [$booleanValue];
            }
        }
        $skipped[] = (string)($meta['name'] ?? 'Boolean') . ' не отправлен: значение должно быть Да/Нет.';
        return [];
    }

    $warrantyValue = supplier_push_ozon_warranty_value_for_meta($meta);
    if ($warrantyValue !== '') {
        $values = [$warrantyValue];
    } elseif (supplier_push_ozon_is_fits_for_meta($meta)) {
        $values = supplier_push_ozon_fits_for_brand_values($values, $bundle, $taxonomyMeta);
        if (!$values) {
            $skipped[] = 'Подходит для не отправлен: не удалось определить бренд совместимости.';
            return [];
        }
    } else {
        $values = supplier_push_split_attribute_values($values);
    }

    $maxCount = supplier_push_ozon_attribute_max_count($meta);
    if ($maxCount === 1) {
        $values = supplier_push_first_attribute_value($values);
    } elseif ($maxCount > 1 && count($values) > $maxCount) {
        $values = array_slice($values, 0, $maxCount);
    }

    return $values;
}

function supplier_push_ozon_payload_attributes(array $bundle, array $fields, array &$skipped, ?array $oz = null, array $cfg = [], bool $includeVideoCover = true): array
{
    $product = (array)($bundle['product'] ?? []);
    $ozonCategory = (string)($product['ozon_category'] ?? '');
    $meta = supplier_push_taxonomy_meta('ozon', (string)($product['ozon_category'] ?? ''));
    $attrMeta = is_array($meta['ozon_required_attributes_meta'] ?? null) ? $meta['ozon_required_attributes_meta'] : [];
    $attrMeta = supplier_push_ozon_enrich_attr_meta_with_live_complex_ids($attrMeta, $meta, $oz);

    $attributes = [];

    if (supplier_push_ozon_payload_should_include_model($fields)) {
        $attr = supplier_push_ozon_model_attribute($bundle, $attrMeta);
        if ($attr) {
            $attributes[] = $attr;
        } elseif (!empty($fields['model'])) {
            $skipped[] = 'model_empty_for_ozon_model_attribute';
        }
    }

    if (!empty($fields['brand'])) {
        $brandMeta = supplier_push_find_meta_by_name($attrMeta, ['Бренд', 'Бренд товара']);
        $id = (int)($brandMeta['id'] ?? 85);
        $brand = trim((string)($bundle['brand_ozon'] ?? ''));
        if ($brand === '') {
            $brand = trim((string)($bundle['brand'] ?? ''));
        }
        $brandValue = $brand;
        if ($brand !== '') {
            $descriptionCategoryId = (int)($meta['ozon_description_category_id'] ?? 0);
            $typeId = (int)($meta['ozon_type_id'] ?? 0);
            if ($descriptionCategoryId > 0 && $typeId > 0 && $id > 0) {
                try {
                    static $brandResolveCache = [];
                    $brandCacheKey = $descriptionCategoryId . '|' . $typeId . '|' . $id . '|' . supplier_push_norm_name($brand);
                    if (array_key_exists($brandCacheKey, $brandResolveCache)) {
                        $resolvedBrand = $brandResolveCache[$brandCacheKey];
                    } else {
                        $resolvedBrand = marketplace_ozon_brand_resolve($oz, $ozonCategory, $descriptionCategoryId, $typeId, $id, $brand);
                        $brandResolveCache[$brandCacheKey] = is_array($resolvedBrand) ? $resolvedBrand : null;
                    }
                    if (is_array($resolvedBrand) && (int)($resolvedBrand['brand_id'] ?? 0) > 0) {
                        $brandValue = [
                            'dictionary_value_id' => (int)$resolvedBrand['brand_id'],
                            'value' => (string)($resolvedBrand['brand_name'] ?? $brand),
                        ];
                    } else {
                        $skipped[] = 'brand "' . $brand . '" не найден в справочнике брендов Ozon для категории; отправлено строковое значение';
                    }
                } catch (Throwable $e) {
                    $skipped[] = 'brand "' . $brand . '" не удалось проверить в справочнике Ozon: ' . $e->getMessage();
                }
            }
        }
        $attr = supplier_push_ozon_attribute($id, [$brandValue]);
        if ($attr) {
            $attributes[] = $attr;
        }
    }

    if (!empty($fields['description'])) {
        $id = supplier_push_ozon_annotation_attribute_id($attrMeta);
        $description = supplier_push_limit_text((string)($bundle['description'] ?? ''), 6000);
        $attr = supplier_push_ozon_attribute($id, [$description]);
        if ($attr) {
            $attributes[] = $attr;
        } else {
            $skipped[] = 'description_empty_for_ozon_annotation';
        }
    }

    if (!empty($fields['characteristics'])) {
        $hashtags = supplier_push_bundle_hashtags_value($bundle);
        if ($hashtags !== '') {
            $hashtagsMeta = supplier_push_find_meta_by_name($attrMeta, ['#Хештеги', 'Хештеги', 'hashtags']);
            $id = (int)($hashtagsMeta['id'] ?? 23171);
            $attr = supplier_push_ozon_attribute($id, [$hashtags]);
            if ($attr) {
                $attributes[] = $attr;
            }
        }
    }

    if (supplier_push_should_send_ozon_rich_content($fields)) {
        $rich = supplier_push_ozon_build_rich_content_json($bundle, $cfg);
        if ($rich !== '') {
            $id = supplier_push_ozon_rich_content_attribute_id($attrMeta);
            $attr = supplier_push_ozon_attribute($id, [$rich]);
            if ($attr) {
                $attributes[] = $attr;
            }
        }
    }

    if ($includeVideoCover && !empty($fields['video'])) {
        $videoCoverUrl = supplier_push_video_cover_url($bundle);
        if ($videoCoverUrl !== '') {
            $attr = supplier_push_ozon_video_cover_attribute($attrMeta, $videoCoverUrl);
            if ($attr) {
                $attributes[] = $attr;
            }
        } else {
            $skipped[] = 'video_cover_empty';
        }
    }

    if (!empty($fields['characteristics']) || !empty($fields['tnved'])) {
        $characteristicValuesById = [];
        $characteristicMetaById = [];
        foreach ((array)($bundle['params'] ?? []) as $name => $values) {
            $nameNorm = supplier_push_norm_name((string)$name);
            $isTnvedParam = supplier_push_is_tnved_attribute_name((string)$name);
            if ($isTnvedParam && empty($fields['tnved'])) {
                continue;
            }
            if (empty($fields['characteristics']) && (!$isTnvedParam || empty($fields['tnved']))) {
                continue;
            }
            if (supplier_products_is_hashtags_field_name((string)$name)
                || supplier_push_is_model_attribute_name((string)$name)
                || in_array($nameNorm, [
                    'rich контент json',
                    'rich content json',
                    'озон видео название',
                    'озон видео ссылка',
                    'озон видео товары на видео',
                    'озон видеообложка ссылка',
                    'ozon videocover link',
                    'видеообложка',
                    'видео обложка',
                ], true)
            ) {
                continue;
            }
            $paramMetas = supplier_push_find_all_meta_by_name($attrMeta, [(string)$name]);
            if (!$paramMetas) {
                continue;
            }
            foreach ($paramMetas as $paramMeta) {
                $id = (int)($paramMeta['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                if (supplier_push_ozon_meta_complex_id($paramMeta) > 0) {
                    continue;
                }
                $characteristicMetaById[$id] = $paramMeta;
                foreach (supplier_push_values_for_attribute_names([(array)($bundle['params'] ?? [])], [(string)$name, (string)($paramMeta['name'] ?? '')]) as $value) {
                    $characteristicValuesById[$id][] = $value;
                }
            }
        }
        if (!empty($fields['tnved'])) {
            foreach ($attrMeta as $paramMeta) {
                if (!is_array($paramMeta) || !supplier_push_ozon_is_marking_code_meta($paramMeta)) {
                    continue;
                }
                $id = (int)($paramMeta['id'] ?? 0);
                if ($id <= 0 || supplier_push_ozon_meta_complex_id($paramMeta) > 0) {
                    continue;
                }
                $markingValue = '';
                $markingInputs = supplier_push_values_for_attribute_names(
                    [(array)($bundle['params'] ?? [])],
                    [(string)($paramMeta['name'] ?? ''), 'Нужен код маркировки', 'Требуется код маркировки']
                );
                foreach ($markingInputs as $markingInput) {
                    $markingValue = supplier_push_ozon_boolean_value($markingInput);
                    if ($markingValue !== '') {
                        break;
                    }
                }
                $characteristicMetaById[$id] = $paramMeta;
                $characteristicValuesById[$id] = [$markingValue !== '' ? $markingValue : 'false'];
                break;
            }
        }
        foreach ($attrMeta as $paramMeta) {
            if (!is_array($paramMeta) || !supplier_push_ozon_is_warranty_two_years_meta($paramMeta)) {
                continue;
            }
            $id = (int)($paramMeta['id'] ?? 0);
            if ($id <= 0 || supplier_push_ozon_meta_complex_id($paramMeta) > 0) {
                continue;
            }
            $characteristicMetaById[$id] = $paramMeta;
            $characteristicValuesById[$id] = [supplier_push_ozon_warranty_value_for_meta($paramMeta)];
        }
        foreach ($characteristicValuesById as $id => $values) {
            $paramMeta = (array)($characteristicMetaById[$id] ?? []);
            $cleanValues = supplier_push_marketplace_attribute_values((array)$values, $paramMeta, 'ozon');
            $cleanValues = supplier_push_ozon_prepare_attribute_values_for_payload($cleanValues, $paramMeta, $bundle, $meta, $skipped);
            if (!$cleanValues) {
                continue;
            }
            $cleanValues = supplier_push_ozon_dictionary_values_for_payload($cleanValues, $paramMeta, $meta, $oz, $skipped);
            $attr = supplier_push_ozon_attribute((int)$id, $cleanValues);
            if ($attr) {
                $attributes[] = $attr;
            }
        }
    }

    return $attributes;
}

function supplier_push_ozon_is_fits_for_meta(array $meta): bool
{
    $name = (string)($meta['name'] ?? '');
    $key = supplier_push_norm_name($name);
    return in_array($key, [
        'подходит для',
        'для чего подходит',
        'для бренда',
        'совместимый бренд',
        'бренд совместимости',
    ], true);
}

function supplier_push_ozon_is_compatible_models_meta(array $meta): bool
{
    $name = (string)($meta['name'] ?? '');
    $key = supplier_push_norm_name($name);
    return in_array($key, [
        'совместимые модели',
        'совместимые модели устройства',
        'совместимые модели товара',
        'модели совместимости',
        'модель совместимости',
        'совместимость с моделями',
        'подходит для моделей',
    ], true);
}

function supplier_push_ozon_is_release_type_meta(array $meta): bool
{
    $name = (string)($meta['name'] ?? '');
    if (function_exists('supplier_products_is_ozon_release_type_characteristic_name')) {
        return supplier_products_is_ozon_release_type_characteristic_name($name);
    }
    return supplier_push_norm_name($name) === 'вид выпуска товара';
}

function supplier_push_ozon_is_marking_code_meta(array $meta): bool
{
    $name = supplier_push_norm_name((string)($meta['name'] ?? ''));
    return in_array($name, [
        'нужен код маркировки',
        'требуется код маркировки',
        'нужна маркировка',
    ], true);
}

function supplier_push_ozon_boolean_value($value): string
{
    $value = supplier_push_norm_name((string)$value);
    if ($value === '') {
        return '';
    }
    if (in_array($value, ['true', '1', 'yes', 'да', 'нужен', 'нужна', 'требуется'], true)) {
        return 'true';
    }
    if (in_array($value, ['false', '0', 'no', 'нет', 'не нужен', 'не нужна', 'не требуется'], true)) {
        return 'false';
    }
    return '';
}

function supplier_push_ozon_attr_with_one_value(array $attr, array $value): array
{
    $attr['values'] = [[
        'dictionary_value_id' => (int)($value['dictionary_value_id'] ?? ($value['id'] ?? 0)),
        'value' => trim((string)($value['value'] ?? ($value['name'] ?? ''))),
    ]];
    return $attr;
}

function supplier_push_ozon_complex_attribute_groups_from_attrs(
    int $complexId,
    array $attrs,
    array $metaById,
    array &$skipped
): array {
    $complexId = max(0, $complexId);
    if ($complexId <= 0 || !$attrs) {
        return [];
    }

    $fitsAttr = null;
    $modelsAttr = null;
    $otherAttrs = [];
    foreach ($attrs as $attr) {
        if (!is_array($attr)) {
            continue;
        }
        $id = (int)($attr['id'] ?? 0);
        if ($id <= 0 || empty($attr['values']) || !is_array($attr['values'])) {
            continue;
        }
        $meta = (array)($metaById[$id] ?? []);
        if ($fitsAttr === null && supplier_push_ozon_is_fits_for_meta($meta)) {
            $fitsAttr = $attr;
            continue;
        }
        if ($modelsAttr === null && supplier_push_ozon_is_compatible_models_meta($meta)) {
            $modelsAttr = $attr;
            continue;
        }
        $otherAttrs[] = $attr;
    }

    $groups = [];
    $limit = 100;

    if ($fitsAttr !== null || $modelsAttr !== null) {
        $brandValues = is_array($fitsAttr['values'] ?? null) ? array_values((array)$fitsAttr['values']) : [];
        $modelValues = is_array($modelsAttr['values'] ?? null) ? array_values((array)$modelsAttr['values']) : [];
        if (!$brandValues && !$modelValues) {
            return [];
        }
        if (!$modelValues) {
            foreach ($brandValues as $brandValue) {
                if (count($groups) >= $limit) {
                    break;
                }
                $groupAttrs = [];
                if ($fitsAttr !== null && is_array($brandValue)) {
                    $groupAttrs[] = supplier_push_ozon_attr_with_one_value($fitsAttr, $brandValue);
                }
                if ($groupAttrs) {
                    $groups[] = ['complex_id' => $complexId, 'attributes' => $groupAttrs];
                }
            }
            return $groups;
        }

        foreach ($modelValues as $idx => $modelValue) {
            if (count($groups) >= $limit) {
                $skipped[] = 'complex attribute ' . $complexId . ' truncated to 100 combinations';
                break;
            }
            $groupAttrs = [];
            if ($fitsAttr !== null && $brandValues) {
                $brandValue = count($brandValues) === 1
                    ? $brandValues[0]
                    : ($brandValues[$idx] ?? $brandValues[0]);
                if (is_array($brandValue)) {
                    $groupAttrs[] = supplier_push_ozon_attr_with_one_value($fitsAttr, $brandValue);
                }
            }
            if ($modelsAttr !== null && is_array($modelValue)) {
                $groupAttrs[] = supplier_push_ozon_attr_with_one_value($modelsAttr, $modelValue);
            }
            foreach ($otherAttrs as $otherAttr) {
                $values = array_values((array)($otherAttr['values'] ?? []));
                $value = $values[$idx] ?? (count($values) === 1 ? $values[0] : null);
                if (is_array($value)) {
                    $groupAttrs[] = supplier_push_ozon_attr_with_one_value($otherAttr, $value);
                }
            }
            if ($groupAttrs) {
                $groups[] = ['complex_id' => $complexId, 'attributes' => $groupAttrs];
            }
        }
        return $groups;
    }

    $maxValues = 1;
    foreach ($attrs as $attr) {
        $maxValues = max($maxValues, count((array)($attr['values'] ?? [])));
    }
    $maxValues = min($maxValues, $limit);
    for ($idx = 0; $idx < $maxValues; $idx++) {
        $groupAttrs = [];
        foreach ($attrs as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $values = array_values((array)($attr['values'] ?? []));
            $value = $values[$idx] ?? (count($values) === 1 ? $values[0] : null);
            if (is_array($value)) {
                $groupAttrs[] = supplier_push_ozon_attr_with_one_value($attr, $value);
            }
        }
        if ($groupAttrs) {
            $groups[] = ['complex_id' => $complexId, 'attributes' => $groupAttrs];
        }
    }
    return $groups;
}

function supplier_push_ozon_payload_complex_attributes(array $bundle, array $fields, array &$skipped, ?array $oz = null): array
{
    if (empty($fields['characteristics']) && empty($fields['tnved'])) {
        return [];
    }

    $product = (array)($bundle['product'] ?? []);
    $taxonomyMeta = supplier_push_taxonomy_meta('ozon', (string)($product['ozon_category'] ?? ''));
    $attrMeta = is_array($taxonomyMeta['ozon_required_attributes_meta'] ?? null) ? $taxonomyMeta['ozon_required_attributes_meta'] : [];
    $attrMeta = supplier_push_ozon_enrich_attr_meta_with_live_complex_ids($attrMeta, $taxonomyMeta, $oz);
    if (!$attrMeta) {
        return [];
    }

    $valuesByComplexAndId = [];
    $metaById = [];
    foreach ((array)($bundle['params'] ?? []) as $name => $values) {
        $nameNorm = supplier_push_norm_name((string)$name);
        $isTnvedParam = supplier_push_is_tnved_attribute_name((string)$name);
        if ($isTnvedParam && empty($fields['tnved'])) {
            continue;
        }
        if (empty($fields['characteristics']) && (!$isTnvedParam || empty($fields['tnved']))) {
            continue;
        }
        if (supplier_products_is_hashtags_field_name((string)$name)
            || supplier_push_is_model_attribute_name((string)$name)
            || in_array($nameNorm, [
                'rich контент json',
                'rich content json',
                'озон видео название',
                'озон видео ссылка',
                'озон видео товары на видео',
                'озон видеообложка ссылка',
                'ozon videocover link',
                'видеообложка',
                'видео обложка',
            ], true)
        ) {
            continue;
        }
        foreach (supplier_push_find_all_meta_by_name($attrMeta, [(string)$name]) as $paramMeta) {
            $id = (int)($paramMeta['id'] ?? 0);
            $complexId = supplier_push_ozon_meta_complex_id($paramMeta);
            if ($id <= 0 || $complexId <= 0) {
                continue;
            }
            $metaById[$id] = $paramMeta;
            foreach (supplier_push_values_for_attribute_names([(array)($bundle['params'] ?? [])], [(string)$name, (string)($paramMeta['name'] ?? '')]) as $value) {
                $valuesByComplexAndId[$complexId][$id][] = $value;
            }
        }
    }

    $groups = [];
    foreach ($valuesByComplexAndId as $complexId => $valuesById) {
        $attrs = [];
        foreach ($valuesById as $id => $values) {
            $paramMeta = (array)($metaById[(int)$id] ?? []);
            $cleanValues = supplier_push_marketplace_attribute_values((array)$values, $paramMeta, 'ozon');
            $cleanValues = supplier_push_ozon_prepare_attribute_values_for_payload($cleanValues, $paramMeta, $bundle, $taxonomyMeta, $skipped);
            if (!$cleanValues) {
                continue;
            }
            $cleanValues = supplier_push_ozon_dictionary_values_for_payload($cleanValues, $paramMeta, $taxonomyMeta, $oz, $skipped);
            $attr = supplier_push_ozon_attribute((int)$id, $cleanValues, (int)$complexId);
            if ($attr) {
                $attrs[] = $attr;
            }
        }
        $groups = array_merge(
            $groups,
            supplier_push_ozon_complex_attribute_groups_from_attrs((int)$complexId, $attrs, $metaById, $skipped)
        );
    }
    return $groups;
}

function supplier_push_ozon_product_ids(int $connectionId, array $offerIds): array
{
    $offerIds = array_values(array_filter(array_unique(array_map('strval', $offerIds)), static fn($v) => trim($v) !== ''));
    if (!$offerIds) {
        return [];
    }
    ozon_products_tables_ensure();
    $placeholders = implode(',', array_fill(0, count($offerIds), '?'));
    $st = db()->prepare("
        SELECT offer_id, product_id
        FROM feedtools_ozon_products
        WHERE connection_id = ?
          AND offer_id IN ({$placeholders})
          AND product_id IS NOT NULL
    ");
    $st->execute(array_merge([$connectionId], $offerIds));
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $offerId = trim((string)($row['offer_id'] ?? ''));
        $productId = (int)($row['product_id'] ?? 0);
        if ($offerId !== '' && $productId > 0) {
            $out[$offerId] = $productId;
        }
    }
    return $out;
}

function supplier_push_ozon_product_ids_from_api(array $oz, array $offerIds): array
{
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    if (!$offerIds) {
        return [];
    }

    $out = [];
    foreach (array_chunk($offerIds, 100) as $chunk) {
        $resp = ozon_post_json($oz, '/v3/product/info/list', [
            'offer_id' => array_values($chunk),
        ]);
        foreach (supplier_push_ozon_response_items($resp) as $item) {
            $offerId = trim((string)($item['offer_id'] ?? ''));
            $productId = (int)($item['id'] ?? ($item['product_id'] ?? 0));
            if ($offerId !== '' && $productId > 0) {
                $out[$offerId] = $productId;
            }
        }
    }
    return $out;
}

function supplier_push_ozon_fetch_info_items(array $oz, array $offerIds, ?callable $progress = null): array
{
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    if (!$offerIds) {
        return [];
    }

    $out = [];
    $done = 0;
    $total = count($offerIds);
    foreach (array_chunk($offerIds, 100) as $chunk) {
        $resp = ozon_post_json($oz, '/v3/product/info/list', [
            'offer_id' => array_values($chunk),
        ]);
        foreach (supplier_push_ozon_response_items($resp) as $item) {
            $offerId = trim((string)($item['offer_id'] ?? ''));
            if ($offerId !== '') {
                $out[$offerId] = $item;
            }
        }
        $done += count($chunk);
        if ($progress) {
            $progress(min($done, $total), max(1, $total), 'ozon_info', 'Получаю карточки Ozon');
        }
    }
    return $out;
}

function supplier_push_ozon_merge_info_items(array $currentItems, array $infoItems): array
{
    $copyKeys = [
        'id',
        'product_id',
        'name',
        'images',
        'primary_image',
        'images360',
        'color_image',
        'barcode',
        'barcodes',
        'currency_code',
        'vat',
        'depth',
        'width',
        'height',
        'dimension_unit',
        'weight',
        'weight_unit',
        'complex_attributes',
        'pdf_list',
    ];

    foreach ($infoItems as $offerId => $info) {
        if (!is_array($info)) {
            continue;
        }
        $base = (array)($currentItems[$offerId] ?? []);
        foreach ($copyKeys as $key) {
            if (array_key_exists($key, $info)) {
                $base[$key] = $info[$key];
            }
        }
        $currentItems[$offerId] = $base;
    }

    return $currentItems;
}

function supplier_push_ozon_response_items(array $resp): array
{
    $candidates = [
        $resp['result']['items'] ?? null,
        $resp['result'] ?? null,
        $resp['items'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $items = [];
        foreach ($candidate as $row) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }
        if ($items) {
            return $items;
        }
    }
    return [];
}

function supplier_push_ozon_response_task_id(array $resp): int
{
    foreach ([
        $resp['task_id'] ?? null,
        $resp['result']['task_id'] ?? null,
        $resp['result']['taskId'] ?? null,
        $resp['taskId'] ?? null,
    ] as $value) {
        $taskId = (int)$value;
        if ($taskId > 0) {
            return $taskId;
        }
    }
    return 0;
}

function supplier_push_throw_if_cancel_requested(int $opId): void
{
    if ($opId > 0 && function_exists('ops_is_cancel_requested') && ops_is_cancel_requested($opId)) {
        throw new RuntimeException('Cancelled by user');
    }
}

function supplier_push_rethrow_if_cancel_requested(Throwable $e, int $opId): void
{
    if ($opId > 0 && function_exists('ops_is_cancel_requested') && ops_is_cancel_requested($opId)) {
        throw $e;
    }
    if ($e->getMessage() === 'Cancelled by user') {
        throw $e;
    }
}

function supplier_push_ozon_prefetch_progress_done(int $baseTotal, int $phaseDone, int $phaseTotal): int
{
    $baseTotal = max(1, $baseTotal);
    $phaseTotal = max(1, $phaseTotal);
    $phaseDone = max(0, min($phaseDone, $phaseTotal));
    return ($baseTotal * 3) + (int)floor(($phaseDone / $phaseTotal) * $baseTotal);
}

function supplier_push_ozon_import_task_messages(array $resp, int $taskId): array
{
    $items = supplier_push_ozon_response_items($resp);
    $messages = [];
    foreach ($items as $item) {
        $offerId = trim((string)($item['offer_id'] ?? ''));
        $status = trim((string)($item['status'] ?? ''));
        $statusNorm = supplier_push_lc($status);
        foreach ((array)($item['errors'] ?? []) as $error) {
            if (!is_array($error)) {
                continue;
            }
            $level = supplier_push_lc(trim((string)($error['level'] ?? '')));
            if ($level !== '' && $level !== 'error') {
                continue;
            }
            $attributeName = trim((string)($error['attribute_name'] ?? ''));
            $description = trim((string)($error['description'] ?? ($error['message'] ?? '')));
            $code = trim((string)($error['code'] ?? ''));
            $parts = [];
            if ($offerId !== '') {
                $parts[] = $offerId;
            }
            if ($attributeName !== '') {
                $parts[] = $attributeName;
            }
            if ($description !== '') {
                $parts[] = $description;
            } elseif ($code !== '') {
                $parts[] = $code;
            }
            if ($parts) {
                $messages[] = 'Ozon task #' . $taskId . ': ' . implode(' — ', $parts);
            }
        }
        if (empty($item['errors'])) {
            $isProblemStatus = $statusNorm !== ''
                && (str_contains($statusNorm, 'error')
                    || str_contains($statusNorm, 'fail')
                    || str_contains($statusNorm, 'reject')
                    || str_contains($statusNorm, 'skip'));
            if ($isProblemStatus) {
                $messages[] = 'Ozon task #' . $taskId . ': ' . ($offerId !== '' ? ($offerId . ' — ') : '') . 'status=' . $status;
            }
        }
    }

    return array_values(array_unique(array_filter($messages, static fn(string $value): bool => trim($value) !== '')));
}

function supplier_push_ozon_item_label(array $item): string
{
    $offerId = trim((string)($item['offer_id'] ?? ''));
    return $offerId !== '' ? $offerId : 'без offer_id';
}

function supplier_push_ozon_send_items_resilient(
    array $oz,
    string $endpoint,
    array $items,
    int $batchNo,
    int $opId,
    callable $log,
    array &$stats,
    int &$workDone,
    int $workTotal,
    string $statKey,
    string $stage,
    string $label,
    array $createdOfferSet = [],
    int $depth = 0,
    bool $deferTaskCheck = false
): void {
    $count = count($items);
    if ($count <= 0) {
        return;
    }

    $progress = static function () use ($opId, &$workDone, $workTotal, $stage, $label): void {
        ops_update_progress($opId, min($workDone, $workTotal), max(1, $workTotal), $stage, $label);
    };

    try {
        $log('[ozon ' . $label . '] batch ' . $batchNo . ($depth > 0 ? '.' . $depth : '') . ': items=' . $count . "\n");
        $resp = ozon_post_json($oz, $endpoint, ['items' => array_values($items)]);
        $stats[$statKey] = (int)($stats[$statKey] ?? 0) + $count;
        if ($statKey === 'import_items_sent' && $createdOfferSet) {
            foreach ($items as $item) {
                $offerId = (string)($item['offer_id'] ?? '');
                if ($offerId !== '' && isset($createdOfferSet[$offerId])) {
                    $stats['created_items_sent'] = (int)($stats['created_items_sent'] ?? 0) + 1;
                }
            }
        }
        $workDone += $count;

        $taskId = supplier_push_ozon_response_task_id($resp);
        $taskCheck = [];
        $taskMessages = [];
        if ($taskId > 0) {
            if ($deferTaskCheck) {
                $stats['pending_task_checks'][] = [
                    'endpoint' => $endpoint,
                    'batch' => $batchNo,
                    'count' => $count,
                    'task_id' => $taskId,
                    'label' => $label,
                ];
            } else {
                $taskCheck = supplier_push_ozon_check_import_task($oz, $taskId, $count <= 10 ? 6 : 4, 2);
                $taskMessages = (array)($taskCheck['messages'] ?? []);
                supplier_push_ozon_record_task_messages($stats, $log, $taskMessages);
            }
        }
        $stats['responses'][] = [
            'endpoint' => $endpoint,
            'batch' => $batchNo,
            'count' => $count,
            'task_id' => $taskId ?: null,
            'task_checked' => !$deferTaskCheck && (bool)($taskCheck['checked'] ?? false),
            'task_deferred' => $deferTaskCheck && $taskId > 0,
            'task_errors' => count($taskMessages),
        ];
        $progress();
        return;
    } catch (Throwable $e) {
        if ($count <= 1) {
            $labelOffer = supplier_push_ozon_item_label((array)$items[0]);
            $stats['skipped'][] = $label . ': не удалось отправить ' . $labelOffer . ': ' . $e->getMessage();
            $stats['ozon_send_errors'] = (int)($stats['ozon_send_errors'] ?? 0) + 1;
            $stats['responses'][] = [
                'endpoint' => $endpoint,
                'offer_id' => $labelOffer,
                'count' => 1,
                'error' => $e->getMessage(),
            ];
            $workDone++;
            $log('[ozon ' . $label . '] batch ' . $batchNo . ': item error ' . $labelOffer . ': ' . $e->getMessage() . "\n");
            $progress();
            return;
        }

        $log('[ozon ' . $label . '] batch ' . $batchNo . ': error, split ' . $count . ' items: ' . $e->getMessage() . "\n");
        $mid = max(1, intdiv($count, 2));
        supplier_push_ozon_send_items_resilient(
            $oz,
            $endpoint,
            array_slice($items, 0, $mid),
            $batchNo,
            $opId,
            $log,
            $stats,
            $workDone,
            $workTotal,
            $statKey,
            $stage,
            $label,
            $createdOfferSet,
            $depth + 1,
            $deferTaskCheck
        );
        supplier_push_ozon_send_items_resilient(
            $oz,
            $endpoint,
            array_slice($items, $mid),
            $batchNo,
            $opId,
            $log,
            $stats,
            $workDone,
            $workTotal,
            $statKey,
            $stage,
            $label,
            $createdOfferSet,
            $depth + 1,
            $deferTaskCheck
        );
    }
}

function supplier_push_ozon_record_task_messages(array &$stats, callable $log, array $taskMessages): void
{
    foreach ($taskMessages as $message) {
        $message = trim((string)$message);
        if ($message === '') {
            continue;
        }
        $stats['skipped'][] = $message;
        $stats['ozon_task_errors'] = (int)($stats['ozon_task_errors'] ?? 0) + 1;
        $log("[ozon task] {$message}\n");
    }
}

function supplier_push_ozon_check_deferred_tasks(array $oz, array &$stats, callable $log, int $opId): void
{
    $pending = array_values((array)($stats['pending_task_checks'] ?? []));
    if (!$pending) {
        return;
    }

    $states = [];
    foreach ($pending as $idx => $task) {
        $count = max(1, (int)($task['count'] ?? 1));
        $states[$idx] = [
            'task' => $task,
            'attempts' => 0,
            'max_attempts' => $count <= 10 ? 6 : 4,
            'min_stable_attempts' => $count > 10 ? 3 : 2,
            'last_error' => '',
        ];
    }

    $total = count($states);
    $checked = 0;
    $pass = 0;
    $log('[ozon task] проверяем отложенные задачи: ' . $total . "\n");
    while ($states) {
        $pass++;
        foreach (array_keys($states) as $idx) {
            $state = &$states[$idx];
            $task = (array)$state['task'];
            $taskId = (int)($task['task_id'] ?? 0);
            $count = max(1, (int)($task['count'] ?? 1));
            $state['attempts']++;
            $attempt = (int)$state['attempts'];

            try {
                $resp = ozon_post_json($oz, '/v1/product/import/info', ['task_id' => $taskId]);
                $items = supplier_push_ozon_response_items($resp);
                if ($items) {
                    $messages = supplier_push_ozon_import_task_messages($resp, $taskId);
                    if (!$messages && $attempt < min((int)$state['max_attempts'], (int)$state['min_stable_attempts'])) {
                        unset($state);
                        continue;
                    }
                    supplier_push_ozon_record_task_messages($stats, $log, $messages);
                    $stats['responses'][] = [
                        'endpoint' => (string)($task['endpoint'] ?? ''),
                        'batch' => (int)($task['batch'] ?? 0),
                        'count' => $count,
                        'task_id' => $taskId,
                        'task_checked' => true,
                        'task_errors' => count($messages),
                    ];
                    unset($states[$idx]);
                    $checked++;
                    unset($state);
                    continue;
                }
            } catch (Throwable $e) {
                $state['last_error'] = $e->getMessage();
            }

            if ($attempt >= (int)$state['max_attempts']) {
                $message = 'Ozon task #' . $taskId . ': не удалось подтвердить результат задачи'
                    . ($state['last_error'] !== '' ? ': ' . $state['last_error'] : '');
                supplier_push_ozon_record_task_messages($stats, $log, [$message]);
                $stats['responses'][] = [
                    'endpoint' => (string)($task['endpoint'] ?? ''),
                    'batch' => (int)($task['batch'] ?? 0),
                    'count' => $count,
                    'task_id' => $taskId,
                    'task_checked' => false,
                    'task_errors' => 1,
                ];
                unset($states[$idx]);
                $checked++;
            }
            unset($state);
        }

        ops_update_progress(
            $opId,
            $checked,
            max(1, $total),
            'ozon_check',
            'Ozon проверка задач: ' . $checked . '/' . $total
        );
        if ($states) {
            sleep(2);
        }
    }
    unset($stats['pending_task_checks']);
}

function supplier_push_ozon_check_import_task(array $oz, int $taskId, int $attempts = 3, int $delaySec = 2): array
{
    if ($taskId <= 0) {
        return ['task_id' => 0, 'checked' => false, 'messages' => []];
    }

    $lastResp = [];
    $lastError = '';
    $attempts = max(1, $attempts);
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        if ($attempt > 1) {
            sleep(max(1, $delaySec));
        }
        try {
            $lastResp = ozon_post_json($oz, '/v1/product/import/info', ['task_id' => $taskId]);
            $items = supplier_push_ozon_response_items($lastResp);
            if ($items) {
                $messages = supplier_push_ozon_import_task_messages($lastResp, $taskId);
                $minStableAttempts = count($items) > 10 ? 3 : 2;
                if (!$messages && $attempt < min($attempts, $minStableAttempts)) {
                    continue;
                }
                return [
                    'task_id' => $taskId,
                    'checked' => true,
                    'messages' => $messages,
                    'response' => $lastResp,
                ];
            }
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
        }
    }

    return [
        'task_id' => $taskId,
        'checked' => false,
        'messages' => $lastError !== '' ? ['Ozon task #' . $taskId . ': не удалось проверить результат задачи: ' . $lastError] : [],
        'response' => $lastResp,
    ];
}

function supplier_push_ozon_fetch_current_items(array $oz, array $offerIds, ?callable $progress = null): array
{
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    if (!$offerIds) {
        return [];
    }

    $out = [];
    $done = 0;
    $total = count($offerIds);
    foreach (array_chunk($offerIds, 100) as $chunk) {
        $payload = [
            'filter' => [
                'offer_id' => array_values($chunk),
                'visibility' => 'ALL',
            ],
            'limit' => count($chunk),
        ];
        try {
            $resp = ozon_post_json($oz, '/v4/product/info/attributes', $payload);
            foreach (supplier_push_ozon_response_items($resp) as $item) {
                $offerId = trim((string)($item['offer_id'] ?? ''));
                if ($offerId !== '') {
                    $out[$offerId] = $item;
                }
            }
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'item not found') !== false) {
                continue;
            }
            throw $e;
        }
        $done += count($chunk);
        if ($progress) {
            $progress(min($done, $total), max(1, $total), 'ozon_attributes', 'Получаю атрибуты Ozon');
        }
    }
    return $out;
}

function supplier_push_ozon_fetch_price_items(array $oz, array $offerIds, ?callable $progress = null): array
{
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    if (!$offerIds) {
        return [];
    }

    $out = [];
    $done = 0;
    $total = count($offerIds);
    foreach (array_chunk($offerIds, 100) as $chunk) {
        $resp = ozon_post_json($oz, '/v5/product/info/prices', [
            'filter' => ['offer_id' => array_values($chunk)],
            'limit' => count($chunk),
        ]);
        foreach (supplier_push_ozon_response_items($resp) as $item) {
            $offerId = trim((string)($item['offer_id'] ?? ''));
            if ($offerId !== '') {
                $out[$offerId] = $item;
            }
        }
        $done += count($chunk);
        if ($progress) {
            $progress(min($done, $total), max(1, $total), 'ozon_prices', 'Получаю текущие цены Ozon');
        }
    }
    return $out;
}

function supplier_push_ozon_money_string($value): string
{
    if (is_array($value)) {
        $value = $value['price'] ?? $value['value'] ?? '';
    }
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }
    $num = supplier_push_parse_number($raw);
    if ($num === null || $num <= 0) {
        return '';
    }
    return supplier_push_marketplace_price_rub_string($num);
}

function supplier_push_marketplace_price_rub_string(float $value): string
{
    if ($value <= 0 || !is_finite($value)) {
        return '';
    }
    return (string)max(1, (int)round($value));
}

function supplier_push_ozon_price_field(array $priceItem, array $paths): string
{
    foreach ($paths as $path) {
        $cursor = $priceItem;
        foreach (explode('.', $path) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                $cursor = null;
                break;
            }
            $cursor = $cursor[$part];
        }
        $value = supplier_push_ozon_money_string($cursor);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function supplier_push_ozon_current_attributes(array $current): array
{
    $attrs = [];
    foreach ((array)($current['attributes'] ?? []) as $attr) {
        $clean = supplier_push_ozon_clean_current_attribute($attr);
        if ($clean !== null) {
            $attrs[] = $clean;
        }
    }
    return $attrs;
}

function supplier_push_ozon_url_attribute_ids(): array
{
    return [
        8789 => true,  // Название файла PDF
        8790 => true,  // Документ PDF
        21837 => true, // Озон.Видео: название
        21841 => true, // Озон.Видео: ссылка
        21845 => true, // Озон.Видеообложка: ссылка
        22273 => true, // Озон.Видео: товары на видео
    ];
}

function supplier_push_ozon_value_looks_like_bad_url(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    $lc = supplier_push_lc($value);
    $looksLikeUrl = str_starts_with($lc, 'http://')
        || str_starts_with($lc, 'https://')
        || str_starts_with($lc, 'www.');
    return $looksLikeUrl && filter_var($value, FILTER_VALIDATE_URL) === false;
}

function supplier_push_ozon_clean_current_attribute($attr): ?array
{
    if (!is_array($attr)) {
        return null;
    }
    $id = (int)($attr['id'] ?? 0);
    if ($id <= 0 || isset(supplier_push_ozon_url_attribute_ids()[$id])) {
        return null;
    }
    if ((int)($attr['complex_id'] ?? 0) > 0) {
        return null;
    }

    $values = [];
    foreach ((array)($attr['values'] ?? []) as $value) {
        if (!is_array($value)) {
            $text = trim((string)$value);
            if ($text === '' || supplier_push_ozon_value_looks_like_bad_url($text)) {
                continue;
            }
            $values[] = ['dictionary_value_id' => 0, 'value' => $text];
            continue;
        }

        $dictionaryValueId = (int)($value['dictionary_value_id'] ?? ($value['id'] ?? 0));
        $text = trim((string)($value['value'] ?? ($value['name'] ?? '')));
        if ($dictionaryValueId <= 0 && $text === '') {
            continue;
        }
        if ($text !== '' && supplier_push_ozon_value_looks_like_bad_url($text)) {
            continue;
        }
        $values[] = [
            'dictionary_value_id' => $dictionaryValueId,
            'value' => $text,
        ];
    }

    if (!$values) {
        return null;
    }
    return [
        'complex_id' => (int)($attr['complex_id'] ?? 0),
        'id' => $id,
        'values' => $values,
    ];
}

function supplier_push_ozon_merge_attributes(array $currentAttrs, array $nextAttrs): array
{
    $out = [];
    $replace = [];
    foreach ($nextAttrs as $attr) {
        if (!is_array($attr)) {
            continue;
        }
        $id = (int)($attr['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $key = (int)($attr['complex_id'] ?? 0) . ':' . $id;
        $replace[$key] = $attr;
    }
    foreach ($currentAttrs as $attr) {
        if (!is_array($attr)) {
            continue;
        }
        $id = (int)($attr['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $key = (int)($attr['complex_id'] ?? 0) . ':' . $id;
        if (isset($replace[$key])) {
            $out[] = $replace[$key];
            unset($replace[$key]);
        } else {
            $out[] = $attr;
        }
    }
    foreach ($replace as $attr) {
        $out[] = $attr;
    }
    return $out;
}

function supplier_push_ozon_complex_group_id(array $group): int
{
    $groupId = (int)($group['complex_id'] ?? 0);
    if ($groupId > 0) {
        return $groupId;
    }
    foreach ((array)($group['attributes'] ?? []) as $attr) {
        if (!is_array($attr)) {
            continue;
        }
        $attrComplexId = (int)($attr['complex_id'] ?? 0);
        if ($attrComplexId > 0) {
            return $attrComplexId;
        }
    }
    return 0;
}

function supplier_push_ozon_merge_complex_attributes(array $currentComplexAttributes, array $nextComplexAttributes): array
{
    if (!$nextComplexAttributes) {
        return $currentComplexAttributes;
    }

    $replaceComplexIds = [];
    foreach ($nextComplexAttributes as $group) {
        if (!is_array($group)) {
            continue;
        }
        $complexId = supplier_push_ozon_complex_group_id($group);
        if ($complexId > 0) {
            $replaceComplexIds[$complexId] = true;
        }
    }

    $out = [];
    foreach ($currentComplexAttributes as $group) {
        if (!is_array($group)) {
            continue;
        }
        $complexId = supplier_push_ozon_complex_group_id($group);
        if ($complexId > 0 && isset($replaceComplexIds[$complexId])) {
            continue;
        }
        $out[] = $group;
    }
    foreach ($nextComplexAttributes as $group) {
        if (is_array($group) && !empty($group['attributes'])) {
            $out[] = $group;
        }
    }
    return $out;
}

function supplier_push_ozon_merge_video_cover_complex_attributes(array $currentComplexAttributes, array $videoCoverAttr): array
{
    $out = [];
    foreach ($currentComplexAttributes as $group) {
        if (!is_array($group)) {
            continue;
        }
        $attrs = [];
        foreach ((array)($group['attributes'] ?? []) as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $id = (int)($attr['id'] ?? ($attr['attribute_id'] ?? 0));
            $complexId = (int)($attr['complex_id'] ?? 0);
            if ($id === 21845 || ($id > 0 && $id === (int)($videoCoverAttr['id'] ?? 0) && $complexId === 100002)) {
                continue;
            }
            $attrs[] = $attr;
        }
        if ($attrs) {
            $group['attributes'] = $attrs;
            $out[] = $group;
        }
    }
    $out[] = [
        'complex_id' => 100002,
        'attributes' => [$videoCoverAttr],
    ];
    return $out;
}

function supplier_push_ozon_normalized_images($value): array
{
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($value as $item) {
        $url = is_array($item) ? (string)($item['file_name'] ?? $item['url'] ?? '') : (string)$item;
        $url = trim($url);
        if ($url === '' || isset($seen[$url]) || !supplier_push_is_http_url($url)) {
            continue;
        }
        $seen[$url] = true;
        $out[] = $url;
    }
    return $out;
}

function supplier_push_ozon_first_normalized_image($value): string
{
    if (is_string($value)) {
        $url = trim($value);
        return supplier_push_is_http_url($url) ? $url : '';
    }
    $images = supplier_push_ozon_normalized_images($value);
    return (string)($images[0] ?? '');
}

function supplier_push_ozon_ordered_current_images(array $current): array
{
    $primaryImage = supplier_push_ozon_first_normalized_image($current['primary_image'] ?? '');
    $images = supplier_push_ozon_normalized_images($current['images'] ?? []);
    $out = [];
    $seen = [];

    $append = static function (string $url) use (&$out, &$seen): void {
        $url = trim($url);
        if ($url === '' || isset($seen[$url]) || !supplier_push_is_http_url($url)) {
            return;
        }
        $seen[$url] = true;
        $out[] = $url;
    };

    $append($primaryImage);
    foreach ($images as $url) {
        $append((string)$url);
    }

    return $out;
}

function supplier_push_ozon_bundle_public_images_for_payload(array $bundle, array $cfg, string $offerId, array &$skipped): array
{
    $photoStats = [];
    $images = supplier_push_ozon_public_image_urls((array)($bundle['pictures'] ?? []), $cfg, $photoStats, $offerId, 15);
    foreach ((array)($photoStats['skipped'] ?? []) as $skip) {
        $skip = trim((string)$skip);
        if ($skip !== '') {
            $skipped[] = $skip;
        }
    }
    return $images;
}

function supplier_push_ozon_import_image_fields(array $orderedImages): array
{
    $orderedImages = supplier_push_ozon_normalized_images($orderedImages);
    if (!$orderedImages) {
        return [];
    }

    $primaryImage = (string)$orderedImages[0];
    if (count($orderedImages) === 1) {
        return [
            'images' => [$primaryImage],
        ];
    }

    $images = [];
    foreach (array_slice($orderedImages, 1) as $url) {
        $url = trim((string)$url);
        if ($url !== '' && $url !== $primaryImage) {
            $images[] = $url;
        }
    }

    return [
        'primary_image' => $primaryImage,
        'images' => array_slice(array_values($images), 0, 14),
    ];
}

function supplier_push_ozon_category_ids(array $bundle, array $current): array
{
    $descriptionCategoryId = (int)($current['description_category_id'] ?? 0);
    $typeId = (int)($current['type_id'] ?? 0);
    if ($descriptionCategoryId > 0 && $typeId > 0) {
        return [$descriptionCategoryId, $typeId];
    }

    $product = (array)($bundle['product'] ?? []);
    $meta = supplier_push_taxonomy_meta('ozon', (string)($product['ozon_category'] ?? ''));
    if ($descriptionCategoryId <= 0) {
        $descriptionCategoryId = (int)($meta['ozon_description_category_id'] ?? 0);
    }
    if ($typeId <= 0) {
        $typeId = (int)($meta['ozon_type_id'] ?? 0);
    }
    return [$descriptionCategoryId, $typeId];
}

function supplier_push_ozon_build_import_item(array $bundle, array $fields, array $current, array $priceItem, array &$skipped, bool $allowCreate = false, ?array $oz = null, array $cfg = []): ?array
{
    $offerId = trim((string)($bundle['offer_id'] ?? ''));
    if ($offerId === '') {
        return null;
    }
    $isCreate = !$current;
    if ($isCreate && !$allowCreate) {
        $skipped[] = 'Ozon import: товар ' . $offerId . ' не найден в выбранном кабинете Ozon; обновление существующей карточки не выполнено.';
        return null;
    }
    $effectiveFields = $isCreate ? supplier_push_all_content_fields() : $fields;
    if ($isCreate && !empty($fields['video'])) {
        $effectiveFields['video'] = true;
    }
    $dimensionsOnlyUpdate = !$isCreate && supplier_push_only_content_field($effectiveFields, 'dimensions');

    [$descriptionCategoryId, $typeId] = supplier_push_ozon_category_ids($bundle, $current);
    if ($descriptionCategoryId <= 0 || $typeId <= 0) {
        $skipped[] = 'Ozon import: нет description_category_id/type_id для ' . $offerId . '. Сначала назначь/проанализируй категорию Ozon.';
        return null;
    }

    $priceFromCalculatedFallback = false;
    $price = supplier_push_ozon_price_field($priceItem, [
        'price.price',
        'price',
        'marketing_price',
    ]);
    if ($price === '' && $isCreate) {
        $price = supplier_push_ozon_source_price($bundle);
        $priceFromCalculatedFallback = $price !== '';
    }
    if ($price === '') {
        $skipped[] = 'Ozon import: не удалось определить цену для ' . $offerId . '; карточка не отправлена.';
        return null;
    }
    $priceNum = supplier_push_parse_number($price);
    if ($priceFromCalculatedFallback) {
        $modifiedPriceNum = supplier_products_apply_price_modifier(
            $priceNum,
            (string)(((array)($bundle['product'] ?? []))['price_modifier'] ?? '')
        );
        if ($modifiedPriceNum !== null && $modifiedPriceNum > 0) {
            $price = supplier_push_marketplace_price_rub_string($modifiedPriceNum);
        }
    }

    $name = trim((string)($current['name'] ?? ''));
    if (!empty($effectiveFields['name']) && trim((string)($bundle['name'] ?? '')) !== '') {
        $name = supplier_push_ozon_title_for_payload((string)$bundle['name']);
    }
    if ($name === '') {
        $skipped[] = 'Ozon import: нет названия для ' . $offerId;
        return null;
    }

    $item = [
        'offer_id' => $offerId,
        'description_category_id' => $descriptionCategoryId,
        'type_id' => $typeId,
        'name' => $name,
        'price' => $price,
        'currency_code' => (string)($current['currency_code'] ?? ($priceItem['price']['currency_code'] ?? ($priceItem['currency_code'] ?? 'RUB'))),
    ];
    $attrSkipped = [];
    $nextAttributes = supplier_push_ozon_payload_attributes($bundle, $effectiveFields, $attrSkipped, $oz, $cfg, false);
    $nextComplexAttributes = supplier_push_ozon_payload_complex_attributes($bundle, $effectiveFields, $attrSkipped, $oz);
    $attributes = $isCreate
        ? $nextAttributes
        : supplier_push_ozon_merge_attributes(supplier_push_ozon_current_attributes($current), $nextAttributes);
    if ($attributes) {
        $item['attributes'] = $attributes;
    }
    foreach ($attrSkipped as $skip) {
        if ($skip !== 'description_empty_for_ozon_annotation') {
            $skipped[] = 'Ozon import: ' . $skip;
        }
    }

    $oldPrice = $isCreate ? supplier_push_ozon_price_field($priceItem, ['price.old_price', 'old_price']) : '';
    if ($oldPrice !== '') {
        $item['old_price'] = $oldPrice;
    }
    $vat = $isCreate ? trim((string)($current['vat'] ?? ($priceItem['price']['vat'] ?? ($priceItem['vat'] ?? '')))) : '';
    if ($vat !== '') {
        $item['vat'] = $vat;
    }

    if (!$dimensionsOnlyUpdate && ($isCreate || !empty($effectiveFields['description']))) {
        $description = '';
        if (trim((string)($bundle['description'] ?? '')) !== '') {
            $description = mb_substr((string)$bundle['description'], 0, 6000, 'UTF-8');
        } elseif ($isCreate) {
            $description = trim((string)($current['description'] ?? ''));
        }
        if ($description !== '') {
            $item['description'] = $description;
        } elseif (!empty($effectiveFields['description'])) {
            $skipped[] = 'Ozon import: нет описания для ' . $offerId;
        }
    }

    $depth = (int)round((float)($current['depth'] ?? 0));
    $width = (int)round((float)($current['width'] ?? 0));
    $height = (int)round((float)($current['height'] ?? 0));
    $dimensionUnit = trim((string)($current['dimension_unit'] ?? 'mm')) ?: 'mm';
    $weight = (int)round((float)($current['weight'] ?? 0));
    $weightUnit = trim((string)($current['weight_unit'] ?? 'g')) ?: 'g';

    if (!empty($effectiveFields['dimensions'])) {
        $dims = supplier_push_dimensions($bundle);
        $mm = is_array($dims['mm'] ?? null) ? $dims['mm'] : null;
        $weightG = supplier_push_weight_g((string)(((array)($bundle['standard'] ?? []))['weight'] ?? ''));
        if (is_array($mm) && $mm[0] !== null && $mm[1] !== null && $mm[2] !== null && $weightG !== null) {
            $depth = (int)$mm[0];
            $width = (int)$mm[1];
            $height = (int)$mm[2];
            $dimensionUnit = 'mm';
            $weight = $weightG;
            $weightUnit = 'g';
        } else {
            $skipped[] = 'Ozon import: не хватает размеров или веса для ' . $offerId;
        }
    }

    if ($depth <= 0 || $width <= 0 || $height <= 0 || $weight <= 0) {
        $skipped[] = 'Ozon import: нет полных размеров/веса для ' . $offerId;
        return null;
    }
    $item['depth'] = $depth;
    $item['width'] = $width;
    $item['height'] = $height;
    $item['dimension_unit'] = $dimensionUnit;
    $item['weight'] = $weight;
    $item['weight_unit'] = $weightUnit;

    $images = supplier_push_ozon_ordered_current_images($current);
    if (!$images || $isCreate) {
        $bundleImages = supplier_push_ozon_bundle_public_images_for_payload($bundle, $cfg, $offerId, $skipped);
        if ($bundleImages) {
            $images = $bundleImages;
        }
    }
    if ($images) {
        $imageFields = supplier_push_ozon_import_image_fields($images);
        if ($imageFields) {
            if (isset($imageFields['primary_image'])) {
                $item['primary_image'] = (string)$imageFields['primary_image'];
            }
            $item['images'] = array_values((array)$imageFields['images']);
        }
    } else {
        $skipped[] = 'Ozon import: нет доступных публичных фото для ' . $offerId . '; импорт не отправлен, чтобы не очистить фотографии на Ozon.';
        return null;
    }

    if (!$dimensionsOnlyUpdate && ($isCreate || !empty($effectiveFields['photos']))) {
        $images360 = supplier_push_ozon_normalized_images($current['images360'] ?? []);
        if ($images360) {
            $item['images360'] = array_slice($images360, 0, 70);
        }

        $colorImage = supplier_push_ozon_first_normalized_image($current['color_image'] ?? '');
        if ($colorImage !== '') {
            $item['color_image'] = $colorImage;
        }
    }

    $barcode = trim((string)($current['barcode'] ?? ''));
    if ($barcode === '' && $isCreate) {
        $barcode = supplier_push_barcode($bundle);
    }
    if ($barcode !== '') {
        $item['barcode'] = $barcode;
    }
    if (is_array($current['barcodes'] ?? null)) {
        $item['barcodes'] = array_values(array_filter(array_map('strval', $current['barcodes'])));
    }
    if (is_array($current['complex_attributes'] ?? null)) {
        $item['complex_attributes'] = $isCreate
            ? $nextComplexAttributes
            : supplier_push_ozon_merge_complex_attributes((array)$current['complex_attributes'], $nextComplexAttributes);
    } elseif ($nextComplexAttributes) {
        $item['complex_attributes'] = $nextComplexAttributes;
    }
    if (!$dimensionsOnlyUpdate && !empty($effectiveFields['video'])) {
        $videoCoverUrl = supplier_push_video_cover_url($bundle);
        if ($videoCoverUrl !== '') {
            $product = (array)($bundle['product'] ?? []);
            $videoMeta = supplier_push_taxonomy_meta('ozon', (string)($product['ozon_category'] ?? ''));
            $videoAttrMeta = is_array($videoMeta['ozon_required_attributes_meta'] ?? null) ? $videoMeta['ozon_required_attributes_meta'] : [];
            $videoAttr = supplier_push_ozon_video_cover_attribute($videoAttrMeta, $videoCoverUrl);
            if ($videoAttr) {
                $item['complex_attributes'] = supplier_push_ozon_merge_video_cover_complex_attributes(
                    is_array($item['complex_attributes'] ?? null) ? (array)$item['complex_attributes'] : [],
                    $videoAttr
                );
            }
        }
    }
    if (is_array($current['pdf_list'] ?? null)) {
        $item['pdf_list'] = $current['pdf_list'];
    }

    return $item;
}

function supplier_push_ozon(array $cfg, int $datasetId, int $supplierId, int $opId, array $params, callable $log, array $fields): array
{
    [$supplier, $bundles] = supplier_push_load_bundles($supplierId, $params);
    $connectionId = (int)($params['connection_id'] ?? 0);
    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection) || (string)($connection['marketplace'] ?? '') !== 'ozon') {
        throw new RuntimeException('Выбери подключение Ozon.');
    }

    $oz = [
        'client_id' => (string)($connection['client_id'] ?? ''),
        'api_key' => (string)($connection['api_key'] ?? ''),
        'base_url' => (string)($connection['base_url'] ?? 'https://api-seller.ozon.ru'),
        'timeout_sec' => max(10, (int)($connection['timeout_sec'] ?? 30)),
    ];

    $total = count($bundles);
    $enabledFieldNames = supplier_push_enabled_field_names($fields);
    $dimensionsOnly = supplier_push_only_content_field($fields, 'dimensions');
    $fullCardRequested = supplier_push_all_content_fields_selected($fields);
    $log("supplier_db push_marketplace_content: marketplace=ozon, connection_id={$connectionId}, products={$total}\n");
    $log('Ozon selected fields: ' . implode(', ', $enabledFieldNames) . "\n");
    ops_update_progress($opId, 0, max(1, $total), 'ozon', 'готовим данные Ozon');

    $stats = [
        'marketplace' => 'ozon',
        'products_seen' => $total,
        'selected_fields' => $enabledFieldNames,
        'full_card_requested' => $fullCardRequested,
        'import_items_sent' => 0,
        'dimension_items_sent' => 0,
        'created_items_sent' => 0,
        'attribute_items_sent' => 0,
        'photos_items_sent' => 0,
        'skipped' => [],
        'responses' => [],
    ];

    // Ozon accepts required TN VED attributes through the partial endpoint but may
    // leave the card unchanged. Send them as part of a preserved full-card import.
    $needsImportEndpoint = !empty($fields['name'])
        || !empty($fields['dimensions'])
        || !empty($fields['description'])
        || !empty($fields['tnved']);
    $needsCurrentItems = $needsImportEndpoint || !$fullCardRequested || !empty($fields['photos']);
    $offerIds = array_map(static fn($bundle): string => (string)($bundle['offer_id'] ?? ''), $bundles);
    $currentItems = [];
    $priceItems = [];
    $currentFetchOk = true;
    if ($needsCurrentItems) {
        try {
            if ($needsImportEndpoint) {
                $log("Ozon preflight: fetching current attributes for {$total} products\n");
                $currentItems = supplier_push_ozon_fetch_current_items(
                    $oz,
                    $offerIds,
                    static function (int $done, int $totalRows, string $stage, string $message) use ($opId): void {
                        ops_update_progress($opId, $done, max(1, $totalRows * 4), $stage, $message . ': ' . $done . '/' . $totalRows);
                    }
                );
                $log('Ozon preflight: current attributes fetched=' . count($currentItems) . "\n");
                supplier_push_throw_if_cancel_requested($opId);
                $log("Ozon preflight: fetching current product cards for {$total} products\n");
                $infoItems = supplier_push_ozon_fetch_info_items(
                    $oz,
                    $offerIds,
                    static function (int $done, int $totalRows, string $stage, string $message) use ($opId): void {
                        ops_update_progress($opId, $totalRows + $done, max(1, $totalRows * 4), $stage, $message . ': ' . $done . '/' . $totalRows);
                    }
                );
                $currentItems = supplier_push_ozon_merge_info_items($currentItems, $infoItems);
                $log('Ozon preflight: current cards fetched=' . count($infoItems) . "\n");
            } else {
                $log("Ozon preflight: fetching current product cards for {$total} products\n");
                $currentItems = supplier_push_ozon_fetch_info_items(
                    $oz,
                    $offerIds,
                    static function (int $done, int $totalRows, string $stage, string $message) use ($opId): void {
                        ops_update_progress($opId, $done, max(1, $totalRows), $stage, $message . ': ' . $done . '/' . $totalRows);
                    }
                );
                $log('Ozon preflight: current cards fetched=' . count($currentItems) . "\n");
            }
        } catch (Throwable $e) {
            supplier_push_rethrow_if_cancel_requested($e, $opId);
            $currentFetchOk = false;
            $stats['skipped'][] = 'Ozon import: не удалось проверить текущие карточки: ' . $e->getMessage();
            $currentItems = [];
        }
    }
    if ($needsImportEndpoint && $currentFetchOk) {
        try {
            supplier_push_throw_if_cancel_requested($opId);
            $log("Ozon preflight: fetching current prices for {$total} products\n");
            $priceItems = supplier_push_ozon_fetch_price_items(
                $oz,
                $offerIds,
                static function (int $done, int $totalRows, string $stage, string $message) use ($opId): void {
                    ops_update_progress($opId, ($totalRows * 2) + $done, max(1, $totalRows * 4), $stage, $message . ': ' . $done . '/' . $totalRows);
                }
            );
            $log('Ozon preflight: current prices fetched=' . count($priceItems) . "\n");
        } catch (Throwable $e) {
            supplier_push_rethrow_if_cancel_requested($e, $opId);
            $stats['skipped'][] = 'Ozon import: не удалось получить текущие цены: ' . $e->getMessage();
            $priceItems = [];
        }
    }

    $prepareUnitsTotal = max(1, $total * 3);
    $prepareUnitsDone = 0;
    $prepareProgress = static function (string $stage, string $message, int $displayDone, int $displayTotal) use ($opId, $total, $prepareUnitsTotal, &$prepareUnitsDone): void {
        $progressDone = supplier_push_ozon_prefetch_progress_done(max(1, $total), $prepareUnitsDone, $prepareUnitsTotal);
        ops_update_progress(
            $opId,
            $progressDone,
            max(1, max(1, $total) * 4),
            $stage,
            $message . ': ' . $displayDone . '/' . max(1, $displayTotal)
        );
    };

    $fullImportItems = [];
    $fullImportOfferSet = [];
    $createdOfferSet = [];
    $missingOfferSet = [];
    $log("Ozon prepare: building import payloads for {$total} products\n");
    $prepareProgress('ozon_prepare', 'Готовлю карточки Ozon', 0, $total);
    $preparedImportRows = 0;
    foreach ($bundles as $bundle) {
        try {
            $offerId = (string)($bundle['offer_id'] ?? '');
            if ($offerId === '') {
                continue;
            }
            if ($preparedImportRows === 0 || ($preparedImportRows % 10) === 0) {
                $prepareProgress('ozon_prepare', 'Готовлю карточки Ozon ' . $offerId, min($preparedImportRows + 1, $total), $total);
            }
            $current = (array)($currentItems[$offerId] ?? []);
            if ($needsImportEndpoint && $currentFetchOk && !$current) {
                $missingOfferSet[$offerId] = true;
                if (!$fullCardRequested) {
                    $stats['skipped'][] = 'Ozon import: товар ' . $offerId . ' не найден в выбранном кабинете Ozon; частичное обновление (' . implode(', ', $enabledFieldNames) . ') не отправлено, чтобы не создать/перезаписать полную карточку.';
                    continue;
                }
                $importSkipped = [];
                $createFields = supplier_push_all_content_fields();
                if (!empty($fields['video'])) {
                    $createFields['video'] = true;
                }
                $item = supplier_push_ozon_build_import_item($bundle, $createFields, [], [], $importSkipped, true, $oz, $cfg);
                foreach ($importSkipped as $skip) {
                    $stats['skipped'][] = $skip;
                }
                if ($item) {
                    $fullImportItems[] = $item;
                    $fullImportOfferSet[$offerId] = true;
                    $createdOfferSet[$offerId] = true;
                }
                continue;
            }
            if (!$currentFetchOk && !$current && $needsImportEndpoint) {
                $stats['skipped'][] = 'Ozon import: не удалось определить текущую карточку для ' . $offerId . '; частичный импорт не отправлен.';
                continue;
            }

            if ($needsImportEndpoint) {
                $importSkipped = [];
                $item = supplier_push_ozon_build_import_item($bundle, $fields, $current, (array)($priceItems[$offerId] ?? []), $importSkipped, false, $oz, $cfg);
                foreach ($importSkipped as $skip) {
                    $stats['skipped'][] = $skip;
                }
                if ($item) {
                    $fullImportItems[] = $item;
                    $fullImportOfferSet[$offerId] = true;
                }
            }
        } finally {
            $preparedImportRows++;
            $prepareUnitsDone++;
            if ($preparedImportRows === 1 || $preparedImportRows % 5 === 0 || $preparedImportRows >= $total) {
                supplier_push_throw_if_cancel_requested($opId);
                $prepareProgress('ozon_prepare', 'Готовлю карточки Ozon', min($preparedImportRows, $total), $total);
            }
        }
    }
    $log('Ozon prepare: import payloads=' . count($fullImportItems) . '; missing=' . count($missingOfferSet) . "\n");

    $attributeItems = [];
    $localSkipped = [];
    if (!$dimensionsOnly) {
        $log("Ozon prepare: building attribute payloads for {$total} products\n");
        $preparedAttributeRows = 0;
        foreach ($bundles as $bundle) {
            try {
                $offerId = (string)($bundle['offer_id'] ?? '');
                if ($offerId === '') {
                    continue;
                }
                if (isset($fullImportOfferSet[$offerId]) || isset($createdOfferSet[$offerId]) || isset($missingOfferSet[$offerId])) {
                    continue;
                }
                if (!$fullCardRequested) {
                    if (!$currentFetchOk) {
                        $stats['skipped'][] = 'Ozon attributes: не удалось подтвердить текущую карточку для ' . $offerId . '; частичное обновление не отправлено.';
                        continue;
                    }
                    if (empty($currentItems[$offerId])) {
                        $stats['skipped'][] = 'Ozon attributes: товар ' . $offerId . ' не найден в выбранном кабинете Ozon; частичное обновление не отправлено.';
                        continue;
                    }
                }
                $attrs = supplier_push_ozon_payload_attributes($bundle, $fields, $localSkipped, $oz, $cfg);
                if ($attrs) {
                    $attributeItems[] = [
                        'offer_id' => $offerId,
                        'attributes' => $attrs,
                    ];
                }
            } finally {
                $preparedAttributeRows++;
                $prepareUnitsDone++;
                if ($preparedAttributeRows === 1 || $preparedAttributeRows % 5 === 0 || $preparedAttributeRows >= $total) {
                    supplier_push_throw_if_cancel_requested($opId);
                    $prepareProgress('ozon_prepare', 'Готовлю характеристики Ozon', min($preparedAttributeRows, $total), $total);
                }
            }
        }
        $log('Ozon prepare: attribute payloads=' . count($attributeItems) . "\n");
    } else {
        $prepareUnitsDone += $total;
        $prepareProgress('ozon_prepare', 'Характеристики Ozon пропущены', $total, $total);
    }
    foreach (array_unique($localSkipped) as $skip) {
        $stats['skipped'][] = 'Ozon: ' . $skip;
    }

    $photoJobsTotal = 0;
    $preparedPhotoRows = 0;
    if (!empty($fields['photos'])) {
        $log("Ozon prepare: checking photo jobs for {$total} products\n");
        foreach ($bundles as $bundle) {
            try {
                $offerId = (string)($bundle['offer_id'] ?? '');
                if ($offerId !== '' && !isset($createdOfferSet[$offerId]) && !isset($missingOfferSet[$offerId])) {
                    $photoJobsTotal++;
                }
            } finally {
                $preparedPhotoRows++;
                $prepareUnitsDone++;
                if ($preparedPhotoRows === 1 || $preparedPhotoRows % 5 === 0 || $preparedPhotoRows >= $total) {
                    supplier_push_throw_if_cancel_requested($opId);
                    $prepareProgress('ozon_prepare', 'Готовлю фото Ozon', min($preparedPhotoRows, $total), $total);
                }
            }
        }
        $log('Ozon prepare: photo jobs=' . $photoJobsTotal . "\n");
    } else {
        $prepareUnitsDone += $total;
        $prepareProgress('ozon_prepare', 'Фото Ozon пропущены', $total, $total);
    }
    $workTotal = max(1, count($fullImportItems) + count($attributeItems) + $photoJobsTotal);
    $workDone = 0;
    $deferOzonTaskCheck = (count($fullImportItems) + count($attributeItems)) > 1;
    $log(
        'Ozon prepared: ' . ($dimensionsOnly ? 'dimensions=' : 'import=') . count($fullImportItems)
        . '; attributes=' . count($attributeItems)
        . '; photos=' . $photoJobsTotal
        . '; skipped=' . count((array)($stats['skipped'] ?? []))
        . '; task_check=' . ($deferOzonTaskCheck ? 'deferred' : 'inline')
        . "\n"
    );

    $importStatKey = $dimensionsOnly ? 'dimension_items_sent' : 'import_items_sent';
    $importLabel = $dimensionsOnly ? 'Ozon dimensions' : 'Ozon import';
    foreach (array_chunk($fullImportItems, 100) as $idx => $chunk) {
        if (!$chunk) {
            continue;
        }
        $endpoint = '/v3/product/import';
        supplier_push_ozon_send_items_resilient(
            $oz,
            $endpoint,
            $chunk,
            $idx + 1,
            $opId,
            $log,
            $stats,
            $workDone,
            $workTotal,
            $importStatKey,
            'ozon',
            $importLabel,
            $createdOfferSet,
            0,
            $deferOzonTaskCheck
        );
    }

    foreach (array_chunk($attributeItems, 100) as $idx => $chunk) {
        if (!$chunk) {
            continue;
        }
        supplier_push_ozon_send_items_resilient(
            $oz,
            '/v1/product/attributes/update',
            $chunk,
            $idx + 1,
            $opId,
            $log,
            $stats,
            $workDone,
            $workTotal,
            'attribute_items_sent',
            'ozon',
            'Ozon attributes',
            [],
            0,
            $deferOzonTaskCheck
        );
    }

    supplier_push_ozon_check_deferred_tasks($oz, $stats, $log, $opId);

    if (!empty($fields['photos'])) {
        $offerIds = array_map(static fn($bundle): string => (string)($bundle['offer_id'] ?? ''), $bundles);
        $productIds = supplier_push_ozon_product_ids($connectionId, $offerIds);
        $missingPhotoIds = array_values(array_filter($offerIds, static fn($offerId): bool => trim($offerId) !== '' && empty($productIds[$offerId])));
        if ($missingPhotoIds) {
            try {
                $productIds = array_replace($productIds, supplier_push_ozon_product_ids_from_api($oz, $missingPhotoIds));
            } catch (Throwable $e) {
                $stats['skipped'][] = 'Ozon photo: не удалось получить product_id из API: ' . $e->getMessage();
            }
        }
        foreach ($bundles as $bundle) {
            $offerId = (string)($bundle['offer_id'] ?? '');
            if (isset($createdOfferSet[$offerId]) || isset($missingOfferSet[$offerId])) {
                continue;
            }
            $current = is_array($currentItems[$offerId] ?? null) ? (array)$currentItems[$offerId] : [];
            if (!$fullCardRequested) {
                if (!$currentFetchOk) {
                    $stats['skipped'][] = 'Ozon photo: не удалось подтвердить текущую карточку для ' . $offerId . '; частичное обновление фото не отправлено.';
                    continue;
                }
                if (!$current) {
                    $stats['skipped'][] = 'Ozon photo: товар ' . $offerId . ' не найден в выбранном кабинете Ozon; частичное обновление фото не отправлено.';
                    continue;
                }
            }
            $productId = (int)($productIds[$offerId] ?? ($current['id'] ?? ($current['product_id'] ?? 0)));
            $pictures = supplier_push_ozon_public_image_urls((array)($bundle['pictures'] ?? []), $cfg, $stats, $offerId, 15);
            if (!$pictures) {
                $stats['skipped'][] = 'Ozon photo: нет фото для ' . $offerId;
                continue;
            }
            if ($productId <= 0) {
                $stats['skipped'][] = 'Ozon photo: не найден product_id для ' . $offerId . '. Сначала синхронизируй товары Ozon.';
                continue;
            }
            $currentBlocker = supplier_push_ozon_current_card_update_blocker($current);
            if ($currentBlocker !== '') {
                $stats['skipped'][] = 'Ozon photo: товар ' . $offerId . ' сейчас нельзя обновить через API: ' . $currentBlocker;
                continue;
            }
            $photoAttempts = [
                ['mode' => 'full', 'images' => array_values($pictures)],
            ];
            $compactPictures = supplier_push_ozon_compact_fallback_images($pictures, $cfg);
            if ($compactPictures && !supplier_push_ozon_same_image_list($compactPictures, $pictures)) {
                $photoAttempts[] = ['mode' => 'compact', 'images' => $compactPictures];
            }
            $minimalPictures = supplier_push_ozon_minimal_fallback_images($pictures);
            if (
                $minimalPictures
                && !supplier_push_ozon_same_image_list($minimalPictures, $pictures)
                && !supplier_push_ozon_same_image_list($minimalPictures, $compactPictures)
            ) {
                $photoAttempts[] = ['mode' => 'cover_only', 'images' => $minimalPictures];
            }

            $photoSent = false;
            $firstPhotoError = '';
            $lastPhotoError = '';
            foreach ($photoAttempts as $attempt) {
                $attemptMode = (string)($attempt['mode'] ?? 'full');
                $attemptPictures = array_values((array)($attempt['images'] ?? []));
                if (!$attemptPictures) {
                    continue;
                }
                try {
                    $resp = ozon_post_json($oz, '/v1/product/pictures/import', supplier_push_ozon_photo_import_payload($productId, $attemptPictures));
                    $stats['photos_items_sent']++;
                    if ($attemptMode !== 'full') {
                        $stats['ozon_photo_fallback_sent'] = (int)($stats['ozon_photo_fallback_sent'] ?? 0) + 1;
                    }
                    $responseRow = [
                        'endpoint' => '/v1/product/pictures/import',
                        'offer_id' => $offerId,
                        'product_id' => $productId,
                        'pictures' => count($attemptPictures),
                        'images' => array_values($attemptPictures),
                        'result' => $resp['result'] ?? null,
                    ];
                    if ($attemptMode !== 'full') {
                        $responseRow['fallback_mode'] = $attemptMode;
                        $responseRow['original_pictures'] = count($pictures);
                        $responseRow['original_error'] = $firstPhotoError;
                    }
                    $stats['responses'][] = $responseRow;
                    $photoSent = true;
                    break;
                } catch (Throwable $e) {
                    $lastPhotoError = $e->getMessage();
                    if ($firstPhotoError === '') {
                        $firstPhotoError = $lastPhotoError;
                    }
                    if ($attemptMode !== 'full') {
                        $stats['ozon_photo_fallback_errors'] = (int)($stats['ozon_photo_fallback_errors'] ?? 0) + 1;
                    }
                }
            }

            if (!$photoSent) {
                $message = $lastPhotoError !== '' ? $lastPhotoError : ($firstPhotoError !== '' ? $firstPhotoError : 'неизвестная ошибка');
                $stats['skipped'][] = 'Ozon photo: не удалось отправить фото для ' . $offerId . ': ' . $message;
                $stats['ozon_send_errors'] = (int)($stats['ozon_send_errors'] ?? 0) + 1;
                $stats['responses'][] = [
                    'endpoint' => '/v1/product/pictures/import',
                    'offer_id' => $offerId,
                    'product_id' => $productId,
                    'pictures' => count($pictures),
                    'images' => array_values($pictures),
                    'attempts' => array_map(static function (array $attempt): array {
                        return [
                            'mode' => (string)($attempt['mode'] ?? ''),
                            'pictures' => count((array)($attempt['images'] ?? [])),
                        ];
                    }, $photoAttempts),
                    'first_error' => $firstPhotoError,
                    'error' => $message,
                ];
            }
            $workDone++;
            ops_update_progress($opId, min($workDone, $workTotal), $workTotal, 'ozon', 'Ozon photos');
        }
    }

    return supplier_push_report($cfg, $datasetId, $opId, $stats);
}

function supplier_push_wb_characteristic_meta(array $bundle, ?array $card = null): array
{
    $subjectId = (int)($card['subjectID'] ?? 0);
    if ($subjectId <= 0) {
        $product = (array)($bundle['product'] ?? []);
        $subjectId = (int)($product['wb_category'] ?? 0);
    }
    if ($subjectId <= 0) {
        return [];
    }
    $meta = supplier_push_taxonomy_meta('wildberries', (string)$subjectId);
    return is_array($meta['wb_characteristics_meta'] ?? null) ? $meta['wb_characteristics_meta'] : [];
}

function supplier_push_wb_is_packed_weight_characteristic(array $charMeta): bool
{
    $id = (int)($charMeta['id'] ?? ($charMeta['charcID'] ?? 0));
    if ($id === 88952) {
        return true;
    }
    $name = supplier_push_norm_name((string)($charMeta['name'] ?? ''));
    return in_array($name, [
        'вес с упаковкой',
        'вес с упаковкой кг',
        'вес товара с упаковкой',
        'вес товара с упаковкой г',
    ], true);
}

function supplier_push_wb_is_known_numeric_characteristic_name(string $name): bool
{
    $name = supplier_push_norm_name($name);
    return in_array($name, [
        'вес товара без упаковки г',
        'вес товара без упаковки',
        'вес товара с упаковкой г',
        'вес товара с упаковкой',
    ], true);
}

function supplier_push_wb_is_numeric_characteristic(array $charMeta): bool
{
    $charType = (int)($charMeta['charc_type'] ?? ($charMeta['charcType'] ?? 0));
    if ($charType === 4) {
        return true;
    }
    return supplier_push_wb_is_known_numeric_characteristic_name((string)($charMeta['name'] ?? ''));
}

function supplier_push_wb_characteristic_max_count(array $charMeta): int
{
    foreach (['max_count', 'maxCount', 'max_values', 'maxValues'] as $key) {
        if (array_key_exists($key, $charMeta)) {
            $value = (int)$charMeta[$key];
            if ($value > 0) {
                return $value;
            }
        }
    }
    return 0;
}

function supplier_push_wb_number_for_payload($value)
{
    if (is_array($value)) {
        $value = (string)($value['value'] ?? ($value['name'] ?? reset($value) ?: ''));
    }
    $num = supplier_push_parse_number((string)$value);
    if ($num === null) {
        return null;
    }
    $rounded = round($num);
    return abs($num - $rounded) < 1e-9 ? (int)$rounded : (float)$num;
}

function supplier_push_wb_numeric_characteristic_value($value)
{
    $values = is_array($value) ? $value : [$value];
    foreach ($values as $part) {
        $num = supplier_push_wb_number_for_payload($part);
        if ($num !== null) {
            return $num;
        }
    }
    return null;
}

function supplier_push_wb_is_single_value_characteristic(array $charMeta): bool
{
    if (supplier_push_wb_characteristic_max_count($charMeta) === 1) {
        return true;
    }
    if (supplier_push_wb_is_numeric_characteristic($charMeta)) {
        return true;
    }
    $name = supplier_push_norm_name((string)($charMeta['name'] ?? ''));
    return in_array($name, [
        'модель',
        'модель товара',
        'название модели',
        'страна производства',
        'страна производитель',
        'страна изготовитель',
        'страна происхождения',
    ], true) || supplier_push_is_tnved_attribute_name((string)($charMeta['name'] ?? ''));
}

function supplier_push_wb_limited_characteristic_values(array $values, array $charMeta): array
{
    $values = supplier_push_split_attribute_values($values);
    $values = array_values(array_filter(array_map(
        static fn($value): string => supplier_products_limit_wb_characteristic_value((string)$value),
        $values
    ), static fn(string $value): bool => $value !== ''));
    $maxCount = supplier_push_wb_characteristic_max_count($charMeta);
    if ($maxCount > 0 && count($values) > $maxCount) {
        $values = array_slice($values, 0, $maxCount);
    }
    return $values;
}

function supplier_push_wb_values_for_characteristics(array $bundle, array $fields, array $card): array
{
    $meta = supplier_push_wb_characteristic_meta($bundle, $card);
    if (!$meta) {
        return [];
    }

    $items = [];
    if (!empty($fields['model']) && trim((string)($bundle['model'] ?? '')) !== '') {
        $modelMeta = supplier_push_find_meta_by_name($meta, ['Модель', 'Модель товара']);
        $modelValue = supplier_push_primary_model_text((string)$bundle['model']);
        if ($modelValue !== '' && is_array($modelMeta) && (int)($modelMeta['id'] ?? 0) > 0) {
            $items[(int)$modelMeta['id']] = [
                'id' => (int)$modelMeta['id'],
                'name' => (string)($modelMeta['name'] ?? 'Модель'),
                'charc_type' => (int)($modelMeta['charc_type'] ?? ($modelMeta['charcType'] ?? 0)),
                'value' => [$modelValue],
            ];
        }
    }

    if (!empty($fields['characteristics']) || !empty($fields['tnved'])) {
        $consumeSourceMap = static function (array $sourceMap, bool $isFallback) use (&$items, $meta, $fields): void {
            foreach ($sourceMap as $name => $values) {
                $charMeta = supplier_push_find_meta_by_name($meta, [(string)$name]);
                $id = (int)($charMeta['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $isTnved = supplier_push_is_tnved_attribute_name((string)($charMeta['name'] ?? $name));
                if ($isTnved && empty($fields['tnved'])) {
                    continue;
                }
                if (!$isTnved && empty($fields['characteristics'])) {
                    continue;
                }
                if (supplier_push_wb_is_packed_weight_characteristic((array)$charMeta)) {
                    continue;
                }
                if ($isFallback && isset($items[$id])) {
                    continue;
                }
                $isColor = function_exists('supplier_products_characteristic_is_color_name')
                    && supplier_products_characteristic_is_color_name((string)($charMeta['name'] ?? $name));
                if ($isFallback && $isColor) {
                    continue;
                }
                $clean = supplier_push_values_for_attribute_names([$sourceMap], [(string)$name, (string)($charMeta['name'] ?? '')]);
                $clean = supplier_push_marketplace_attribute_values($clean, (array)$charMeta, 'wildberries');
                if ($clean) {
                    if ($isTnved) {
                        $clean = supplier_push_tnved_values_for_wb_payload($clean);
                        if (!$clean) {
                            continue;
                        }
                    }
                    $isNumeric = supplier_push_wb_is_numeric_characteristic((array)$charMeta);
                    if ($isNumeric) {
                        $clean = supplier_push_wb_numeric_characteristic_value($clean);
                        if ($clean === null) {
                            continue;
                        }
                    }
                    if (supplier_push_wb_is_single_value_characteristic((array)$charMeta)) {
                        if ($isNumeric) {
                            // charcType=4 must be sent as a single JSON number, not as an array.
                        } elseif (supplier_push_is_model_attribute_name((string)($charMeta['name'] ?? $name))) {
                            $clean = supplier_push_first_attribute_value([supplier_push_primary_model_text((string)($clean[0] ?? ''))]);
                        } else {
                            $clean = supplier_push_first_attribute_value($clean);
                        }
                    } elseif (!$isNumeric) {
                        $clean = supplier_push_wb_limited_characteristic_values((array)$clean, (array)$charMeta);
                        if (!$clean) {
                            continue;
                        }
                    }
                    if (!$isNumeric) {
                        if (is_array($clean)) {
                            $clean = array_values(array_filter(array_map(
                                static fn($value): string => supplier_products_limit_wb_characteristic_value((string)$value),
                                $clean
                            ), static fn(string $value): bool => $value !== ''));
                        } else {
                            $clean = supplier_products_limit_wb_characteristic_value((string)$clean);
                        }
                        if ((is_array($clean) && !$clean) || (!is_array($clean) && trim((string)$clean) === '')) {
                            continue;
                        }
                    }
                    if (isset($items[$id])) {
                        if (supplier_push_wb_is_single_value_characteristic((array)$charMeta)) {
                            continue;
                        }
                        $items[$id]['value'] = supplier_push_wb_limited_characteristic_values(
                            array_merge((array)($items[$id]['value'] ?? []), (array)$clean),
                            (array)$charMeta
                        );
                        continue;
                    }
                    $items[$id] = [
                        'id' => $id,
                        'name' => (string)($charMeta['name'] ?? $name),
                        'charc_type' => (int)($charMeta['charc_type'] ?? ($charMeta['charcType'] ?? 0)),
                        'value' => $clean,
                    ];
                }
            }
        };

        $consumeSourceMap((array)($bundle['wb_params'] ?? []), false);
        $consumeSourceMap((array)($bundle['params'] ?? []), true);
    }

    return $items;
}

function supplier_push_wb_fetch_card(WildberriesClient $client, string $vendorCode): ?array
{
    $vendorCode = trim($vendorCode);
    if ($vendorCode === '') {
        return null;
    }
    $resp = $client->getCardsList([
        'sort' => ['ascending' => true],
        'cursor' => ['limit' => 100],
        'filter' => [
            'textSearch' => $vendorCode,
            'withPhoto' => -1,
        ],
    ]);
    $cards = is_array($resp['cards'] ?? null) ? $resp['cards'] : [];
    foreach ($cards as $card) {
        if (!is_array($card)) {
            continue;
        }
        if (trim((string)($card['vendorCode'] ?? '')) === $vendorCode) {
            return $card;
        }
    }
    return null;
}

function supplier_push_wb_merge_characteristics(array $card, array $nextItems): array
{
    $current = is_array($card['characteristics'] ?? null) ? $card['characteristics'] : [];
    $out = [];
    $replaced = [];
    foreach ($current as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int)($item['id'] ?? 0);
        if ($id > 0 && isset($nextItems[$id])) {
            $out[] = $nextItems[$id];
            $replaced[$id] = true;
        } else {
            $out[] = $item;
        }
    }
    foreach ($nextItems as $id => $item) {
        if (!isset($replaced[$id])) {
            $out[] = $item;
        }
    }
    $card['characteristics'] = $out;
    return $card;
}

function supplier_push_wb_vendor_code(array $bundle): string
{
    $offerId = trim((string)($bundle['offer_id'] ?? ''));
    if ($offerId !== '') {
        return $offerId;
    }
    return trim((string)($bundle['vendor_code'] ?? ''));
}

function supplier_push_wb_barcodes_from_response(array $response): array
{
    $candidates = [
        $response['data'] ?? null,
        $response['barcodes'] ?? null,
        $response['result']['data'] ?? null,
        $response['result'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $out = [];
        foreach ($candidate as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $out[] = $value;
            }
        }
        if ($out) {
            return $out;
        }
    }
    return [];
}

function supplier_push_wb_generate_unique_barcodes(WildberriesClient $client, int $count, array &$skipped): array
{
    $count = max(0, $count);
    if ($count <= 0) {
        return [];
    }

    $barcodes = [];
    $seen = [];
    for ($attempt = 1; $attempt <= 3 && count($barcodes) < $count; $attempt++) {
        $missing = $count - count($barcodes);
        try {
            $generated = supplier_push_wb_barcodes_from_response($client->generateBarcodes($missing));
        } catch (Throwable $e) {
            $skipped[] = 'WB create: не удалось сгенерировать уникальные баркоды: ' . $e->getMessage();
            break;
        }

        foreach ($generated as $barcode) {
            $barcode = trim((string)$barcode);
            if ($barcode === '' || isset($seen[$barcode])) {
                continue;
            }
            $seen[$barcode] = true;
            $barcodes[] = $barcode;
            if (count($barcodes) >= $count) {
                break;
            }
        }
    }

    if (count($barcodes) < $count) {
        $skipped[] = 'WB create: WB сгенерировал только ' . count($barcodes) . ' из ' . $count . ' уникальных barcode; карточки без barcode будут пропущены.';
    }
    return $barcodes;
}

function supplier_push_wb_subject_id(array $bundle, ?array $card = null): int
{
    $subjectId = is_array($card) ? (int)($card['subjectID'] ?? 0) : 0;
    if ($subjectId > 0) {
        return $subjectId;
    }
    $product = (array)($bundle['product'] ?? []);
    return (int)($product['wb_category'] ?? 0);
}

function supplier_push_wb_brand_for_payload(
    WildberriesClient $client,
    array $bundle,
    ?array $card,
    string $vendorCode,
    array &$skipped
): string {
    $brand = trim((string)($bundle['brand_wb'] ?? ''));
    if ($brand === '') {
        $brand = trim((string)($bundle['brand'] ?? ''));
    }
    if ($brand === '') {
        return '';
    }
    $subjectId = supplier_push_wb_subject_id($bundle, $card);
    if ($subjectId <= 0) {
        $skipped[] = 'WB brand: нет subjectID для ' . $vendorCode . '; бренд отправлен как в каталоге.';
        return $brand;
    }
    static $resolveCache = [];
    static $warnedCache = [];
    $cacheKey = $subjectId . '|' . supplier_push_norm_name($brand);
    if (array_key_exists($cacheKey, $resolveCache)) {
        return $resolveCache[$cacheKey];
    }
    try {
        $resolved = marketplace_wb_brand_resolve($client, $subjectId, $brand, (string)$subjectId);
        if (is_array($resolved) && trim((string)($resolved['brand_name'] ?? '')) !== '') {
            return $resolveCache[$cacheKey] = trim((string)$resolved['brand_name']);
        }
        if (!isset($warnedCache[$cacheKey])) {
            $skipped[] = 'WB brand: бренд "' . $brand . '" не найден в справочнике WB для subjectID ' . $subjectId . '; отправлено значение из каталога.';
            $warnedCache[$cacheKey] = true;
        }
    } catch (Throwable $e) {
        if (!isset($warnedCache[$cacheKey])) {
            $skipped[] = 'WB brand: не удалось проверить бренд "' . $brand . '" для ' . $vendorCode . ': ' . $e->getMessage();
            $warnedCache[$cacheKey] = true;
        }
    }
    return $resolveCache[$cacheKey] = $brand;
}

function supplier_push_wb_build_create_card(array $bundle, string $vendorCode, string $barcode, array &$skipped, string $payloadBrand = ''): ?array
{
    $product = (array)($bundle['product'] ?? []);
    $subjectId = (int)($product['wb_category'] ?? 0);
    if ($subjectId <= 0) {
        $skipped[] = 'WB create: нет категории WB для ' . $vendorCode;
        return null;
    }

    $brand = trim($payloadBrand) !== '' ? trim($payloadBrand) : trim((string)($bundle['brand_wb'] ?? ''));
    if ($brand === '') {
        $brand = trim((string)($bundle['brand'] ?? ''));
    }
    if ($brand === '') {
        $brand = 'Нет бренда';
        $skipped[] = 'WB create: для ' . $vendorCode . ' бренд пустой, отправлено значение "Нет бренда".';
    }
    $name = supplier_push_wb_title_for_payload($bundle, $brand, $vendorCode);
    if ($name === '') {
        $skipped[] = 'WB create: нет названия для ' . $vendorCode;
        return null;
    }
    $description = supplier_push_wb_description_for_payload((string)($bundle['description'] ?? ''), $name);
    if ($description === '') {
        $description = $name;
    }

    $dims = supplier_push_dimensions($bundle);
    $cm = is_array($dims['cm'] ?? null) ? $dims['cm'] : null;
    $weightKg = supplier_push_weight_kg((string)(((array)($bundle['standard'] ?? []))['weight'] ?? ''));
    if (!is_array($cm) || $cm[0] === null || $cm[1] === null || $cm[2] === null || $weightKg === null) {
        $skipped[] = 'WB create: не хватает размеров или веса для ' . $vendorCode;
        return null;
    }
    $dimensionsPayload = supplier_push_wb_dimensions_payload([
        'length' => $cm[0],
        'width' => $cm[1],
        'height' => $cm[2],
        'weightBrutto' => $weightKg,
    ]);
    if ($dimensionsPayload === null) {
        $skipped[] = 'WB create: некорректные размеры или вес для ' . $vendorCode;
        return null;
    }

    $barcode = trim($barcode);
    if ($barcode === '') {
        $skipped[] = 'WB create: нет уникального barcode WB для ' . $vendorCode;
        return null;
    }

    $fields = supplier_push_all_content_fields();
    $characteristics = array_values(supplier_push_wb_values_for_characteristics($bundle, $fields, ['subjectID' => $subjectId]));

    return [
        'subjectID' => $subjectId,
        'variants' => [[
            'vendorCode' => $vendorCode,
            'title' => $name,
            'description' => $description,
            'brand' => supplier_push_wb_payload_text($brand, 100),
            'dimensions' => $dimensionsPayload,
            'characteristics' => $characteristics,
            'sizes' => [[
                'skus' => [$barcode],
            ]],
        ]],
    ];
}

function supplier_push_wb_dimensions_payload(array $dimensions): ?array
{
    $length = supplier_push_parse_number((string)($dimensions['length'] ?? ''));
    $width = supplier_push_parse_number((string)($dimensions['width'] ?? ''));
    $height = supplier_push_parse_number((string)($dimensions['height'] ?? ''));
    $weightBrutto = supplier_push_parse_number((string)($dimensions['weightBrutto'] ?? ''));

    if ($length === null || $width === null || $height === null || $weightBrutto === null) {
        return null;
    }
    if ($length <= 0 || $width <= 0 || $height <= 0 || $weightBrutto <= 0) {
        return null;
    }

    return [
        'length' => max(1, (int)ceil($length)),
        'width' => max(1, (int)ceil($width)),
        'height' => max(1, (int)ceil($height)),
        'weightBrutto' => round($weightBrutto, 3),
    ];
}

function supplier_push_trimmed_equal($left, $right): bool
{
    return trim((string)$left) === trim((string)$right);
}

function supplier_push_wb_dimension_number($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return round((float)$value, 3);
}

function supplier_push_wb_dimensions_equal($left, $right): bool
{
    if (!is_array($left) || !is_array($right)) {
        return false;
    }
    foreach (['length', 'width', 'height', 'weightBrutto'] as $key) {
        if (supplier_push_wb_dimension_number($left[$key] ?? null) !== supplier_push_wb_dimension_number($right[$key] ?? null)) {
            return false;
        }
    }
    return true;
}

function supplier_push_wb_characteristic_signature_value($value): array
{
    if (is_int($value) || is_float($value)) {
        return ['#number:' . rtrim(rtrim(sprintf('%.6f', (float)$value), '0'), '.')];
    }
    if (is_array($value)) {
        $values = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $text = trim((string)($item['value'] ?? ($item['name'] ?? '')));
            } else {
                $text = trim((string)$item);
            }
            if ($text !== '') {
                $values[] = mb_strtolower(str_replace('ё', 'е', $text), 'UTF-8');
            }
        }
        sort($values, SORT_NATURAL);
        return array_values(array_unique($values));
    }
    $text = trim((string)$value);
    return $text === '' ? [] : [mb_strtolower(str_replace('ё', 'е', $text), 'UTF-8')];
}

function supplier_push_wb_characteristics_signature($items): string
{
    $entries = [];
    foreach ((array)$items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int)($item['id'] ?? ($item['charcID'] ?? ($item['characteristicID'] ?? 0)));
        $name = trim((string)($item['name'] ?? ''));
        $key = $id > 0
            ? ('id:' . $id)
            : ('name:' . mb_strtolower(str_replace('ё', 'е', $name), 'UTF-8'));
        if ($key === 'name:') {
            continue;
        }
        $entries[$key] = supplier_push_wb_characteristic_signature_value($item['value'] ?? []);
    }
    ksort($entries, SORT_NATURAL);
    return json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function supplier_push_wb_prepare_update_card(array $card): array
{
    $out = [];
    foreach (['nmID', 'vendorCode'] as $key) {
        if (array_key_exists($key, $card)) {
            $out[$key] = $card[$key];
        }
    }
    if (array_key_exists('brand', $card)) {
        $brand = supplier_push_wb_payload_text($card['brand'], 100);
        if ($brand !== '') {
            $out['brand'] = $brand;
        }
    }
    if (array_key_exists('title', $card)) {
        $title = supplier_push_wb_payload_text($card['title'], supplier_push_wb_title_limit());
        if ($title !== '') {
            $out['title'] = $title;
        }
    }
    if (array_key_exists('description', $card)) {
        $description = supplier_push_wb_description_for_payload((string)$card['description'], (string)($out['title'] ?? ''));
        if ($description !== '') {
            $out['description'] = $description;
        }
    }
    if (!empty($card['kizMarked']) || !empty($card['needKiz'])) {
        $out['kizMarked'] = true;
    }

    $dimensions = is_array($card['dimensions'] ?? null)
        ? supplier_push_wb_dimensions_payload((array)$card['dimensions'])
        : null;
    if ($dimensions !== null) {
        $out['dimensions'] = $dimensions;
    }

    $characteristics = [];
    foreach ((array)($card['characteristics'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int)($item['id'] ?? 0);
        if ($id <= 0 || !array_key_exists('value', $item)) {
            continue;
        }
        $value = $item['value'];
        if (is_int($value) || is_float($value) || supplier_push_wb_is_numeric_characteristic($item)) {
            $num = supplier_push_wb_numeric_characteristic_value($value);
            if ($num !== null) {
                $characteristics[] = [
                    'id' => $id,
                    'value' => $num,
                ];
            }
            continue;
        }
        if (!is_array($value)) {
            $value = [$value];
        }
        $cleanValue = [];
        foreach ($value as $part) {
            if (is_array($part)) {
                $part = trim((string)($part['value'] ?? ($part['name'] ?? '')));
            } else {
                $part = trim((string)$part);
            }
            $part = supplier_products_limit_wb_characteristic_value($part);
            if ($part !== '') {
                $cleanValue[] = $part;
            }
        }
        if ($cleanValue) {
            $characteristics[] = [
                'id' => $id,
                'value' => array_values(array_unique($cleanValue)),
            ];
        }
    }
    if ($characteristics) {
        $out['characteristics'] = $characteristics;
    }

    $sizes = [];
    foreach ((array)($card['sizes'] ?? []) as $size) {
        if (!is_array($size)) {
            continue;
        }
        $next = [];
        foreach (['chrtID', 'techSize', 'wbSize'] as $key) {
            if (array_key_exists($key, $size)) {
                $next[$key] = $size[$key];
            }
        }
        $skus = [];
        foreach ((array)($size['skus'] ?? []) as $sku) {
            $sku = trim((string)$sku);
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }
        if ($skus) {
            $next['skus'] = array_values(array_unique($skus));
        }
        if ($next) {
            $sizes[] = $next;
        }
    }
    if ($sizes) {
        $out['sizes'] = $sizes;
    }

    return $out;
}

function supplier_push_wb_wait_for_created_card(WildberriesClient $client, string $vendorCode, int $maxWaitSec = 90, int $intervalSec = 5): ?array
{
    $deadline = time() + max(1, $maxWaitSec);
    $intervalSec = max(1, $intervalSec);
    do {
        try {
            $card = supplier_push_wb_fetch_card($client, $vendorCode);
            if (is_array($card) && (int)($card['nmID'] ?? 0) > 0) {
                return $card;
            }
        } catch (Throwable $e) {
            // Card creation is asynchronous; keep polling until the bounded wait expires.
        }
        if (time() >= $deadline) {
            break;
        }
        sleep(min($intervalSec, max(1, $deadline - time())));
    } while (time() < $deadline);

    return null;
}

function supplier_push_wb_card_vendor_label(array $card): string
{
    $vendorCode = trim((string)($card['vendorCode'] ?? ''));
    if ($vendorCode !== '') {
        return $vendorCode;
    }
    foreach ((array)($card['variants'] ?? []) as $variant) {
        if (!is_array($variant)) {
            continue;
        }
        $vendorCode = trim((string)($variant['vendorCode'] ?? ''));
        if ($vendorCode !== '') {
            return $vendorCode;
        }
    }
    return 'без артикула';
}

function supplier_push_wb_fetch_cards_by_vendor_codes(
    WildberriesClient $client,
    array $vendorCodes,
    int $opId,
    callable $log
): array {
    $wanted = [];
    foreach ($vendorCodes as $vendorCode) {
        $vendorCode = trim((string)$vendorCode);
        if ($vendorCode !== '') {
            $wanted[$vendorCode] = true;
        }
    }
    if (!$wanted) {
        return [];
    }

    $limit = 100;
    $found = [];
    $totalWanted = count($wanted);
    $lastProgressAt = 0.0;

    foreach ([false, true] as $ascending) {
        if (count($found) >= $totalWanted) {
            break;
        }
        $cursor = ['limit' => $limit];
        $directionLabel = $ascending ? 'старые сначала' : 'новые сначала';

        for ($page = 1; $page <= 1000; $page++) {
            $resp = $client->getCardsList([
                'sort' => ['ascending' => $ascending],
                'cursor' => $cursor,
                'filter' => ['withPhoto' => -1],
            ]);
            $cards = is_array($resp['cards'] ?? null) ? $resp['cards'] : [];
            foreach ($cards as $card) {
                if (!is_array($card)) {
                    continue;
                }
                $vendorCode = trim((string)($card['vendorCode'] ?? ''));
                if ($vendorCode !== '' && isset($wanted[$vendorCode]) && !isset($found[$vendorCode])) {
                    $found[$vendorCode] = $card;
                }
            }

            $now = microtime(true);
            if ($page === 1 || count($found) >= $totalWanted || ($page % 10) === 0 || (($now - $lastProgressAt) >= 5.0)) {
                $message = sprintf(
                    'WB карточки: найдено %d/%d; %s; загружено страниц %d',
                    count($found),
                    $totalWanted,
                    $directionLabel,
                    $page
                );
                ops_update_progress($opId, min(count($found), $totalWanted), max(1, $totalWanted * 2), 'wb_fetch_cards', $message);
                if ($page === 1 || count($found) >= $totalWanted || ($page % 25) === 0) {
                    $log($message . "\n");
                }
                $lastProgressAt = $now;
            }

            if (count($found) >= $totalWanted) {
                break;
            }

            $batchCursor = is_array($resp['cursor'] ?? null) ? $resp['cursor'] : [];
            $batchTotal = (int)($batchCursor['total'] ?? count($cards));
            if (!$cards || $batchTotal < $limit || empty($batchCursor['updatedAt']) || empty($batchCursor['nmID'])) {
                break;
            }

            $cursor = [
                'limit' => $limit,
                'updatedAt' => (string)$batchCursor['updatedAt'],
                'nmID' => (int)$batchCursor['nmID'],
            ];
        }
    }

    return $found;
}

function supplier_push_wb_fetch_cards_by_vendor_codes_exact(
    WildberriesClient $client,
    array $vendorCodes,
    int $opId,
    callable $log,
    string $progressPrefix = 'WB карточки'
): array {
    $clean = [];
    foreach ($vendorCodes as $vendorCode) {
        $vendorCode = trim((string)$vendorCode);
        if ($vendorCode !== '') {
            $clean[$vendorCode] = true;
        }
    }
    $vendorCodes = array_keys($clean);
    if (!$vendorCodes) {
        return [];
    }

    $found = [];
    $total = count($vendorCodes);
    $lastProgressAt = 0.0;
    foreach ($vendorCodes as $idx => $vendorCode) {
        $done = $idx + 1;
        $now = microtime(true);
        if ($done === 1 || $done === $total || ($done % 10) === 0 || (($now - $lastProgressAt) >= 5.0)) {
            $message = sprintf(
                '%s: точечная проверка %d/%d; найдено %d; %s',
                $progressPrefix,
                $done,
                $total,
                count($found),
                $vendorCode
            );
            ops_update_progress($opId, min($done, $total), max(1, $total), 'wb_fetch_cards_exact', $message);
            if ($done === 1 || $done === $total || ($done % 25) === 0) {
                $log($message . "\n");
            }
            $lastProgressAt = $now;
        }

        try {
            $card = supplier_push_wb_fetch_card($client, $vendorCode);
            if (is_array($card)) {
                $found[$vendorCode] = $card;
            }
        } catch (Throwable $e) {
            $log($progressPrefix . ': не удалось точечно проверить ' . $vendorCode . ': ' . $e->getMessage() . "\n");
        }
    }

    return $found;
}

function supplier_push_wb_fetch_existing_cards_for_push(
    WildberriesClient $client,
    array $vendorCodes,
    int $opId,
    callable $log
): array {
    $clean = [];
    foreach ($vendorCodes as $vendorCode) {
        $vendorCode = trim((string)$vendorCode);
        if ($vendorCode !== '') {
            $clean[$vendorCode] = true;
        }
    }
    $vendorCodes = array_keys($clean);
    if (!$vendorCodes) {
        return [];
    }

    if (count($vendorCodes) <= 3) {
        $found = [];
        foreach ($vendorCodes as $idx => $vendorCode) {
            ops_update_progress(
                $opId,
                $idx,
                max(1, count($vendorCodes) * 2),
                'wb_fetch_cards',
                'WB карточки: проверяем ' . ($idx + 1) . '/' . count($vendorCodes) . '; ' . $vendorCode
            );
            $card = supplier_push_wb_fetch_card($client, $vendorCode);
            if (is_array($card)) {
                $found[$vendorCode] = $card;
            }
        }
        $log('WB cards loaded: found=' . count($found) . '/' . count($vendorCodes) . " через точечный поиск\n");
        return $found;
    }

    return supplier_push_wb_fetch_cards_by_vendor_codes($client, $vendorCodes, $opId, $log);
}

function supplier_push_wb_error_should_split(Throwable $e): bool
{
    $message = supplier_push_lc($e->getMessage());
    foreach ([
        'http 429',
        'global limiter',
        'rate limit',
        'too many requests',
        'limited by',
    ] as $needle) {
        if (str_contains($message, $needle)) {
            return false;
        }
    }
    return true;
}

function supplier_push_wb_update_cards_resilient(
    WildberriesClient $client,
    array $cards,
    int $batchNo,
    int $opId,
    callable $log,
    array &$stats,
    int &$workDone,
    int $prepareTotal,
    int $overallTotal,
    int $depth = 0
): void {
    $count = count($cards);
    if ($count <= 0) {
        return;
    }

    $progress = static function (string $detail = '') use ($opId, &$stats, &$workDone, $prepareTotal, $overallTotal): void {
        $msg = sprintf(
            'WB cards: обработано %d; обновлено %d; пропуски %d',
            $workDone,
            (int)($stats['cards_sent'] ?? 0),
            count((array)($stats['skipped'] ?? []))
        );
        if ($detail !== '') {
            $msg .= '; ' . $detail;
        }
        ops_update_progress($opId, min($prepareTotal + $workDone, $overallTotal), max(1, $overallTotal), 'wb_cards', $msg);
    };

    try {
        $log('[wb update] batch ' . $batchNo . ($depth > 0 ? '.' . $depth : '') . ': items=' . $count . "\n");
        $resp = $client->updateCards($cards);
        $stats['cards_sent'] += $count;
        $workDone += $count;
        $stats['responses'][] = [
            'endpoint' => '/content/v2/cards/update',
            'batch' => $batchNo,
            'count' => $count,
            'response' => $resp,
        ];
        $progress('batch ' . $batchNo);
        return;
    } catch (Throwable $e) {
        if ($count <= 1 || !supplier_push_wb_error_should_split($e)) {
            $label = supplier_push_wb_card_vendor_label((array)$cards[0]);
            $rangeLabel = $count <= 1 ? $label : ('пачку ' . $batchNo . ' (' . $count . ' товаров)');
            $stats['skipped'][] = 'WB update: не удалось обновить ' . $rangeLabel . ': ' . $e->getMessage();
            $stats['responses'][] = [
                'endpoint' => '/content/v2/cards/update',
                'vendor_code' => $count <= 1 ? $label : null,
                'batch' => $batchNo,
                'count' => $count,
                'error' => $e->getMessage(),
            ];
            $workDone += $count;
            $log('[wb update] batch ' . $batchNo . ': '
                . ($count <= 1 ? ('item error ' . $label) : 'batch error without split')
                . ': ' . $e->getMessage() . "\n");
            $progress($count <= 1 ? $label : ('batch ' . $batchNo));
            return;
        }

        $log('[wb update] batch ' . $batchNo . ': error, split ' . $count . ' items: ' . $e->getMessage() . "\n");
        $mid = max(1, intdiv($count, 2));
        supplier_push_wb_update_cards_resilient(
            $client,
            array_slice($cards, 0, $mid),
            $batchNo,
            $opId,
            $log,
            $stats,
            $workDone,
            $prepareTotal,
            $overallTotal,
            $depth + 1
        );
        supplier_push_wb_update_cards_resilient(
            $client,
            array_slice($cards, $mid),
            $batchNo,
            $opId,
            $log,
            $stats,
            $workDone,
            $prepareTotal,
            $overallTotal,
            $depth + 1
        );
    }
}

function supplier_push_wb_prepare_existing_card_for_bundle(
    WildberriesClient $client,
    array $cfg,
    array $bundle,
    array $card,
    string $vendorCode,
    array $fields,
    array &$stats
): array {
    $changedCard = false;
    if (!empty($fields['name']) && trim((string)($bundle['name'] ?? '')) !== '') {
        $title = supplier_push_wb_title_for_payload($bundle, supplier_push_wb_title_brand($bundle, $card), $vendorCode);
        if ($title !== '' && !supplier_push_trimmed_equal($title, $card['title'] ?? '')) {
            $card['title'] = $title;
            $changedCard = true;
        }
    }
    if (!empty($fields['brand']) && (trim((string)($bundle['brand_wb'] ?? '')) !== '' || trim((string)($bundle['brand'] ?? '')) !== '')) {
        $brandSkipped = [];
        $brand = supplier_push_wb_brand_for_payload($client, $bundle, $card, $vendorCode, $brandSkipped);
        foreach ($brandSkipped as $skip) {
            $stats['skipped'][] = $skip;
        }
        if (!supplier_push_trimmed_equal($brand, $card['brand'] ?? '')) {
            $card['brand'] = $brand;
            $changedCard = true;
        }
    }
    if (!empty($fields['description']) && trim((string)($bundle['description'] ?? '')) !== '') {
        $description = supplier_push_wb_description_for_payload((string)$bundle['description'], (string)($card['title'] ?? ''));
        if (!supplier_push_trimmed_equal($description, $card['description'] ?? '')) {
            $card['description'] = $description;
            $stats['descriptions_prepared']++;
            $changedCard = true;
        }
    } elseif (!empty($fields['description'])) {
        $stats['skipped'][] = 'WB description: нет описания для ' . $vendorCode;
    }
    if (!empty($fields['dimensions'])) {
        $dims = supplier_push_dimensions($bundle);
        $cm = is_array($dims['cm'] ?? null) ? $dims['cm'] : null;
        $weightKg = supplier_push_weight_kg((string)(((array)($bundle['standard'] ?? []))['weight'] ?? ''));
        if (is_array($cm) && $cm[0] !== null && $cm[1] !== null && $cm[2] !== null && $weightKg !== null) {
            $dimensionsPayload = supplier_push_wb_dimensions_payload([
                'length' => $cm[0],
                'width' => $cm[1],
                'height' => $cm[2],
                'weightBrutto' => $weightKg,
            ]);
            if ($dimensionsPayload !== null) {
                if (!supplier_push_wb_dimensions_equal((array)($card['dimensions'] ?? []), $dimensionsPayload)) {
                    $card['dimensions'] = $dimensionsPayload;
                    $changedCard = true;
                }
            } else {
                $stats['skipped'][] = 'WB: некорректные размеры или вес для ' . $vendorCode;
            }
        } else {
            $stats['skipped'][] = 'WB: не хватает размеров или веса для ' . $vendorCode;
        }
    }
    if (!empty($fields['model']) || !empty($fields['characteristics']) || !empty($fields['tnved'])) {
        $nextChars = supplier_push_wb_values_for_characteristics($bundle, $fields, $card);
        if ($nextChars) {
            $beforeChars = supplier_push_wb_characteristics_signature($card['characteristics'] ?? []);
            $mergedCard = supplier_push_wb_merge_characteristics($card, $nextChars);
            $afterChars = supplier_push_wb_characteristics_signature($mergedCard['characteristics'] ?? []);
            if ($beforeChars !== $afterChars) {
                $card = $mergedCard;
                $changedCard = true;
            }
        } elseif (!empty($fields['model']) || !empty($fields['characteristics']) || !empty($fields['tnved'])) {
            $stats['skipped'][] = 'WB: не найдены подходящие ID характеристик для ' . $vendorCode;
        }
    }

    $mediaJob = null;
    if (!empty($fields['photos']) || !empty($fields['video'])) {
        $nmId = (int)($card['nmID'] ?? 0);
        $hasVideoForBundle = !empty($fields['video']) && supplier_push_video_cover_url($bundle) !== '';
        if (empty($fields['photos']) && !empty($fields['video']) && !$hasVideoForBundle) {
            $stats['skipped'][] = 'WB video: нет видео-обложки для ' . $vendorCode;
        } else {
            $mediaUrls = supplier_push_wb_media_urls_for_payload($bundle, $cfg, !empty($fields['photos']), !empty($fields['video']), $card);
            if ($nmId > 0 && $mediaUrls) {
                $mediaJob = [
                    'nm_id' => $nmId,
                    'vendor_code' => $vendorCode,
                    'pictures' => array_values($mediaUrls),
                    'has_photos' => !empty($fields['photos']),
                    'has_video' => $hasVideoForBundle,
                ];
            } else {
                $stats['skipped'][] = 'WB media: нет nmID или медиа для ' . $vendorCode;
            }
        }
    }

    return [
        'update_card' => $changedCard ? supplier_push_wb_prepare_update_card($card) : null,
        'media_job' => $mediaJob,
    ];
}

function supplier_push_wb_remap_cache_success(int $connectionId, string $oldVendorCode, string $newVendorCode, array $sourceCard): void
{
    $oldVendorCode = trim($oldVendorCode);
    $newVendorCode = trim($newVendorCode);
    if ($connectionId <= 0 || $newVendorCode === '') {
        return;
    }

    $pdo = db();
    wb_products_ensure_table($pdo);
    $seenAt = date('Y-m-d H:i:s');
    $newCard = $sourceCard;
    $newCard['vendorCode'] = $newVendorCode;
    wb_products_upsert_rows($pdo, wb_products_card_rows([$newCard], 'ready', 1, 0, ''), $seenAt, $connectionId);

    if ($oldVendorCode !== '' && $oldVendorCode !== $newVendorCode) {
        $st = $pdo->prepare("
            UPDATE feedtools_wb_products
            SET is_active = 0,
                marketplace_status = 'renamed',
                status_text = ?,
                last_seen_at = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE connection_id = ?
              AND vendor_code = ?
        ");
        $st->execute([
            'Артикул заменен на ' . $newVendorCode . '. Дождись синхронизации WB для подтверждения.',
            $seenAt,
            $connectionId,
            $oldVendorCode,
        ]);
    }
}

function supplier_push_wb_remap_stock_units(array $row): int
{
    $units = 0;
    foreach (['quantityFull', 'quantity_full', 'quantity'] as $field) {
        if (isset($row[$field]) && is_numeric($row[$field])) {
            $units = max($units, max(0, (int)$row[$field]));
        }
    }

    foreach (['inWayToClient', 'in_way_to_client', 'inWayFromClient', 'in_way_from_client'] as $field) {
        if (isset($row[$field]) && is_numeric($row[$field])) {
            $units += max(0, (int)$row[$field]);
        }
    }
    return $units;
}

function supplier_push_wb_remap_stock_activity_map(WildberriesClient $client, array $cardsByVendorCode, callable $log): array
{
    $wantedVendor = [];
    $wantedNm = [];
    foreach ($cardsByVendorCode as $vendorCode => $card) {
        if (!is_array($card)) {
            continue;
        }
        $cardVendorCode = trim((string)($card['vendorCode'] ?? $vendorCode));
        if ($cardVendorCode !== '') {
            $wantedVendor[$cardVendorCode] = true;
        }
        $nmId = (int)($card['nmID'] ?? 0);
        if ($nmId > 0) {
            $wantedNm[(string)$nmId] = true;
        }
    }
    if (!$wantedVendor && !$wantedNm) {
        return [];
    }

    try {
        $rows = $client->getSupplierStocks('2019-01-01');
    } catch (Throwable $e) {
        $log('WB vendor remap guard: не удалось получить остатки WB; проверка остатков пропущена: ' . $e->getMessage() . "\n");
        return [];
    }
    if (isset($rows['data']) && is_array($rows['data'])) {
        $rows = $rows['data'];
    }
    if (!is_array($rows)) {
        return [];
    }

    $activity = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $units = supplier_push_wb_remap_stock_units($row);
        if ($units <= 0) {
            continue;
        }
        $vendorCode = trim((string)($row['supplierArticle'] ?? ($row['supplier_article'] ?? ($row['vendorCode'] ?? ''))));
        $nmId = (int)($row['nmId'] ?? ($row['nmID'] ?? ($row['nm_id'] ?? 0)));
        $matched = false;
        $summary = [
            'warehouse' => (string)($row['warehouseName'] ?? ($row['warehouse_name'] ?? '')),
            'barcode' => (string)($row['barcode'] ?? ''),
            'quantity' => $units,
            'last_change_date' => (string)($row['lastChangeDate'] ?? ($row['last_change_date'] ?? '')),
        ];
        if ($vendorCode !== '' && isset($wantedVendor[$vendorCode])) {
            $activity['vendor:' . $vendorCode][] = $summary;
            $matched = true;
        }
        if ($nmId > 0 && isset($wantedNm[(string)$nmId])) {
            $activity['nm:' . $nmId][] = $summary;
            $matched = true;
        }
        if (!$matched) {
            continue;
        }
    }

    return $activity;
}

function supplier_push_wb_remap_local_order_count(int $connectionId, string $vendorCode, int $nmId): int
{
    $needles = [];
    if ($vendorCode !== '') {
        $needles[] = '%' . $vendorCode . '%';
    }
    if ($nmId > 0) {
        $needles[] = '%' . (string)$nmId . '%';
    }
    if (!$needles) {
        return 0;
    }

    $pdo = db();
    $total = 0;
    foreach (['feedtools_marketplace_order_snapshots', 'feedtools_marketplace_order_snapshot_history'] as $table) {
        try {
            $parts = array_fill(0, count($needles), 'payload_json LIKE ?');
            $sql = "
                SELECT COUNT(*)
                FROM {$table}
                WHERE connection_id = ?
                  AND marketplace = 'wb'
                  AND (" . implode(' OR ', $parts) . ")
            ";
            $st = $pdo->prepare($sql);
            $st->execute(array_merge([$connectionId], $needles));
            $total += (int)$st->fetchColumn();
        } catch (Throwable $e) {
            // История заказов не обязательна для remap; если таблицы ещё нет, не ломаем операцию.
        }
    }
    return $total;
}

function supplier_push_wb_remap_activity_for_card(
    int $connectionId,
    string $vendorCode,
    array $card,
    array $stockActivity
): array {
    $nmId = (int)($card['nmID'] ?? 0);
    $rows = [];
    $seen = [];
    foreach (['vendor:' . $vendorCode, 'nm:' . $nmId] as $key) {
        foreach ((array)($stockActivity[$key] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowKey = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($rowKey === false || isset($seen[$rowKey])) {
                continue;
            }
            $seen[$rowKey] = true;
            $rows[] = $row;
        }
    }

    $stockUnits = 0;
    foreach ($rows as $row) {
        $stockUnits += max(0, (int)($row['quantity'] ?? 0));
    }

    return [
        'nm_id' => $nmId,
        'stock_units' => $stockUnits,
        'stock_rows' => $rows,
        'local_order_rows' => supplier_push_wb_remap_local_order_count($connectionId, $vendorCode, $nmId),
    ];
}

function supplier_push_wb_remap_activity_blocks(array $activity): bool
{
    return (int)($activity['stock_units'] ?? 0) > 0 || (int)($activity['local_order_rows'] ?? 0) > 0;
}

function supplier_push_wb_remap_update_cards_resilient(
    WildberriesClient $client,
    array $cards,
    int $batchNo,
    int $opId,
    callable $log,
    array &$stats,
    int &$workDone,
    int $prepareTotal,
    int $overallTotal,
    callable $onSuccess,
    int $depth = 0
): void {
    $count = count($cards);
    if ($count <= 0) {
        return;
    }

    $progress = static function (string $detail = '') use ($opId, &$stats, &$workDone, $prepareTotal, $overallTotal): void {
        $msg = sprintf(
            'WB артикулы: отправлено %d; принято %d; ошибки %d; пропуски %d',
            $workDone,
            (int)($stats['updated'] ?? 0),
            (int)($stats['failed'] ?? 0),
            count((array)($stats['skipped'] ?? []))
        );
        if ($detail !== '') {
            $msg .= '; ' . $detail;
        }
        ops_update_progress($opId, min($prepareTotal + $workDone, $overallTotal), max(1, $overallTotal), 'wb_vendor_codes', $msg);
    };

    try {
        $log('[wb vendor remap] batch ' . $batchNo . ($depth > 0 ? '.' . $depth : '') . ': items=' . $count . "\n");
        $resp = $client->updateCards($cards);
        $stats['updated'] += $count;
        $workDone += $count;
        $stats['responses'][] = [
            'endpoint' => '/content/v2/cards/update',
            'batch' => $batchNo,
            'count' => $count,
            'response' => $resp,
        ];
        $onSuccess($cards);
        $progress('batch ' . $batchNo);
        return;
    } catch (Throwable $e) {
        if ($count <= 1 || !supplier_push_wb_error_should_split($e)) {
            $label = supplier_push_wb_card_vendor_label((array)$cards[0]);
            $rangeLabel = $count <= 1 ? $label : ('пачку ' . $batchNo . ' (' . $count . ' товаров)');
            $stats['failed'] += $count;
            $stats['skipped'][] = 'WB vendorCode: не удалось заменить ' . $rangeLabel . ': ' . $e->getMessage();
            $stats['responses'][] = [
                'endpoint' => '/content/v2/cards/update',
                'vendor_code' => $count <= 1 ? $label : null,
                'batch' => $batchNo,
                'count' => $count,
                'error' => $e->getMessage(),
            ];
            $workDone += $count;
            $log('[wb vendor remap] batch ' . $batchNo . ': '
                . ($count <= 1 ? ('item error ' . $label) : 'batch error without split')
                . ': ' . $e->getMessage() . "\n");
            $progress($count <= 1 ? $label : ('batch ' . $batchNo));
            return;
        }

        $log('[wb vendor remap] batch ' . $batchNo . ': error, split ' . $count . ' items: ' . $e->getMessage() . "\n");
        $mid = max(1, intdiv($count, 2));
        supplier_push_wb_remap_update_cards_resilient(
            $client,
            array_slice($cards, 0, $mid),
            $batchNo,
            $opId,
            $log,
            $stats,
            $workDone,
            $prepareTotal,
            $overallTotal,
            $onSuccess,
            $depth + 1
        );
        supplier_push_wb_remap_update_cards_resilient(
            $client,
            array_slice($cards, $mid),
            $batchNo,
            $opId,
            $log,
            $stats,
            $workDone,
            $prepareTotal,
            $overallTotal,
            $onSuccess,
            $depth + 1
        );
    }
}

function supplier_push_wb_remap_vendor_codes_from_csv_file(
    array $cfg,
    int $datasetId,
    int $supplierId,
    int $opId,
    array $params,
    string $csvPath,
    callable $log
): array {
    supplier_products_tables_ensure($cfg);
    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        throw new RuntimeException('Поставщик не найден.');
    }

    $connectionId = (int)($params['connection_id'] ?? ($params['wb_connection_id'] ?? 0));
    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection) || (string)($connection['marketplace'] ?? '') !== 'wb') {
        throw new RuntimeException('Выбери подключение Wildberries.');
    }

    $client = wb_price_tool_client($cfg, $connection);
    $client->setRetryLogger(static function (int $attempt, int $delaySec, string $method, string $api, string $path) use ($log): void {
        $log("WB limiter: {$method} {$api}{$path}, пауза {$delaySec} сек перед повтором #" . ($attempt + 1) . "\n");
    });

    $csv = supplier_products_offer_id_remap_read_csv($csvPath);
    $rawMap = (array)($csv['map'] ?? []);
    $supplierCode = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
    $stats = [
        'marketplace' => 'wb',
        'source_offers' => count($rawMap),
        'rows_total' => (int)($csv['rows_total'] ?? count($rawMap)),
        'updated' => 0,
        'failed' => 0,
        'missing' => 0,
        'conflicts' => 0,
        'noop' => 0,
        'already_replaced' => 0,
        'activity_blocked' => 0,
        'invalid' => (int)($csv['invalid'] ?? 0),
        'duplicates' => (int)($csv['duplicates'] ?? 0),
        'skipped' => [],
        'responses' => [],
        'missing_examples' => [],
        'conflict_examples' => [],
        'invalid_examples' => (array)($csv['invalid_examples'] ?? []),
        'duplicate_examples' => (array)($csv['duplicate_examples'] ?? []),
        'updated_examples' => [],
    ];

    $mappings = [];
    $searchVendorCodes = [];
    $targetSeen = [];
    foreach ($rawMap as $oldRaw => $newRaw) {
        $oldRaw = trim((string)$oldRaw);
        $newRaw = trim((string)$newRaw);
        $newVendorCode = trim(suppliers_apply_supplier_code($newRaw, $supplierCode));
        if ($newVendorCode === '' || (function_exists('mb_strlen') ? mb_strlen($newVendorCode, 'UTF-8') : strlen($newVendorCode)) > 255) {
            $stats['invalid']++;
            if (count($stats['invalid_examples']) < 20) {
                $stats['invalid_examples'][] = [
                    'old' => $oldRaw,
                    'new' => $newRaw,
                    'reason' => 'Новый артикул пустой или длиннее 255 символов',
                ];
            }
            continue;
        }
        if (isset($targetSeen[$newVendorCode])) {
            $stats['conflicts']++;
            if (count($stats['conflict_examples']) < 20) {
                $stats['conflict_examples'][] = [
                    'old' => $oldRaw,
                    'new' => $newRaw,
                    'reason' => 'Новый артикул повторяется в CSV-карте',
                ];
            }
            continue;
        }
        $targetSeen[$newVendorCode] = true;
        $oldCandidates = supplier_products_offer_id_remap_candidates($oldRaw, $supplierCode);
        foreach ($oldCandidates as $candidate) {
            $searchVendorCodes[$candidate] = true;
        }
        $searchVendorCodes[$newVendorCode] = true;
        $mappings[] = [
            'old_raw' => $oldRaw,
            'new_raw' => $newRaw,
            'old_candidates' => $oldCandidates,
            'new_vendor_code' => $newVendorCode,
        ];
    }

    $total = max(1, count($mappings));
    $log('WB vendor remap: mappings=' . count($mappings) . ', rows=' . (int)$stats['rows_total'] . ', connection_id=' . $connectionId . "\n");
    ops_update_progress($opId, 0, max(1, $total * 2), 'wb_fetch_cards', 'WB артикулы: загружаю текущие карточки');

    $cardsByVendorCode = [];
    if ($searchVendorCodes) {
        $cardsByVendorCode = supplier_push_wb_fetch_existing_cards_for_push($client, array_keys($searchVendorCodes), $opId, $log);
    }
    $log('WB vendor remap: cards found=' . count($cardsByVendorCode) . '/' . count($searchVendorCodes) . "\n");

    $allowActivityRemap = !empty($params['allow_wb_vendor_remap_with_activity']);
    $stockActivity = supplier_push_wb_remap_stock_activity_map($client, $cardsByVendorCode, $log);
    if ($allowActivityRemap) {
        $log("WB vendor remap guard: разрешена замена карточек с остатками/заказами WB.\n");
    }

    $cardsToUpdate = [];
    $metaByNewVendor = [];
    $preparedTargets = [];
    $prepared = 0;
    foreach ($mappings as $mapping) {
        $prepared++;
        $oldCard = null;
        $oldVendorCode = '';
        foreach ((array)$mapping['old_candidates'] as $candidate) {
            if (is_array($cardsByVendorCode[$candidate] ?? null)) {
                $oldCard = (array)$cardsByVendorCode[$candidate];
                $oldVendorCode = trim((string)($oldCard['vendorCode'] ?? $candidate));
                break;
            }
        }
        $newVendorCode = (string)$mapping['new_vendor_code'];
        $existingTarget = is_array($cardsByVendorCode[$newVendorCode] ?? null) ? (array)$cardsByVendorCode[$newVendorCode] : null;
        if (!is_array($oldCard)) {
            if (is_array($existingTarget) && trim((string)($existingTarget['vendorCode'] ?? '')) === $newVendorCode) {
                $stats['already_replaced']++;
                if (count($stats['updated_examples']) < 20) {
                    $stats['updated_examples'][] = [
                        'old' => (string)$mapping['old_raw'],
                        'new' => $newVendorCode,
                        'nm_id' => (int)($existingTarget['nmID'] ?? 0),
                        'status' => 'already_replaced',
                    ];
                }
                continue;
            }
            $stats['missing']++;
            if (count($stats['missing_examples']) < 20) {
                $stats['missing_examples'][] = [
                    'old' => (string)$mapping['old_raw'],
                    'new' => (string)$mapping['new_raw'],
                ];
            }
            continue;
        }
        if ($oldVendorCode === $newVendorCode) {
            $stats['noop']++;
            continue;
        }
        if (is_array($existingTarget) && (int)($existingTarget['nmID'] ?? 0) !== (int)($oldCard['nmID'] ?? 0)) {
            $stats['conflicts']++;
            if (count($stats['conflict_examples']) < 20) {
                $stats['conflict_examples'][] = [
                    'old' => $oldVendorCode,
                    'new' => $newVendorCode,
                    'reason' => 'Новый артикул уже занят другой карточкой WB',
                    'conflict_nm_id' => (int)($existingTarget['nmID'] ?? 0),
                ];
            }
            continue;
        }
        if (isset($preparedTargets[$newVendorCode])) {
            $stats['conflicts']++;
            if (count($stats['conflict_examples']) < 20) {
                $stats['conflict_examples'][] = [
                    'old' => $oldVendorCode,
                    'new' => $newVendorCode,
                    'reason' => 'Новый артикул уже подготовлен для другой карточки',
                ];
            }
            continue;
        }

        $activity = supplier_push_wb_remap_activity_for_card($connectionId, $oldVendorCode, $oldCard, $stockActivity);
        if (!$allowActivityRemap && supplier_push_wb_remap_activity_blocks($activity)) {
            $stats['conflicts']++;
            $stats['activity_blocked']++;
            if (count($stats['conflict_examples']) < 20) {
                $firstStock = (array)($activity['stock_rows'][0] ?? []);
                $stats['conflict_examples'][] = [
                    'old' => $oldVendorCode,
                    'new' => $newVendorCode,
                    'reason' => 'У исходной карточки есть остаток/заказы WB; автозамена артикула заблокирована',
                    'nm_id' => (int)($activity['nm_id'] ?? 0),
                    'stock_units' => (int)($activity['stock_units'] ?? 0),
                    'orders_in_local_history' => (int)($activity['local_order_rows'] ?? 0),
                    'warehouse' => (string)($firstStock['warehouse'] ?? ''),
                    'last_change_date' => (string)($firstStock['last_change_date'] ?? ''),
                ];
            }
            continue;
        }

        $payload = supplier_push_wb_prepare_update_card($oldCard);
        $nmId = (int)($payload['nmID'] ?? 0);
        if ($nmId <= 0 || empty($payload['sizes'])) {
            $stats['invalid']++;
            if (count($stats['invalid_examples']) < 20) {
                $stats['invalid_examples'][] = [
                    'old' => $oldVendorCode,
                    'new' => $newVendorCode,
                    'reason' => 'В карточке WB не хватает nmID или sizes для безопасного update',
                ];
            }
            continue;
        }
        $payload['vendorCode'] = $newVendorCode;
        $cardsToUpdate[] = $payload;
        $preparedTargets[$newVendorCode] = true;
        $metaByNewVendor[$newVendorCode] = [
            'old_vendor_code' => $oldVendorCode,
            'new_vendor_code' => $newVendorCode,
            'source_card' => $oldCard,
        ];

        if ($prepared === 1 || ($prepared % 50) === 0 || $prepared === $total) {
            ops_update_progress(
                $opId,
                min($prepared, $total),
                max(1, $total * 2),
                'wb_prepare_vendor_codes',
                'WB артикулы: подготовлено ' . $prepared . '/' . $total . '; к отправке ' . count($cardsToUpdate)
            );
        }
    }

    $workTotal = max(1, count($cardsToUpdate));
    $overallTotal = max(1, $total + $workTotal);
    $workDone = 0;
    $log('WB vendor remap prepared: update=' . count($cardsToUpdate)
        . '; missing=' . (int)$stats['missing']
        . '; conflicts=' . (int)$stats['conflicts']
        . '; activity_blocked=' . (int)$stats['activity_blocked']
        . '; invalid=' . (int)$stats['invalid']
        . '; noop=' . (int)$stats['noop'] . "\n");
    ops_update_progress($opId, min($total, $overallTotal), $overallTotal, 'wb_ready', 'WB артикулы: готово к отправке ' . count($cardsToUpdate));

    $onSuccess = static function (array $cards) use (&$stats, $metaByNewVendor, $connectionId, $log): void {
        foreach ($cards as $card) {
            $newVendorCode = trim((string)($card['vendorCode'] ?? ''));
            $meta = is_array($metaByNewVendor[$newVendorCode] ?? null) ? (array)$metaByNewVendor[$newVendorCode] : null;
            if (!is_array($meta)) {
                continue;
            }
            supplier_push_wb_remap_cache_success(
                $connectionId,
                (string)($meta['old_vendor_code'] ?? ''),
                (string)($meta['new_vendor_code'] ?? ''),
                (array)($meta['source_card'] ?? [])
            );
            if (count($stats['updated_examples']) < 20) {
                $stats['updated_examples'][] = [
                    'old' => (string)($meta['old_vendor_code'] ?? ''),
                    'new' => (string)($meta['new_vendor_code'] ?? ''),
                    'nm_id' => (int)($card['nmID'] ?? 0),
                ];
            }
            $log('WB vendor remap accepted: ' . (string)($meta['old_vendor_code'] ?? '') . ' -> ' . (string)($meta['new_vendor_code'] ?? '') . "\n");
        }
    };

    foreach (array_chunk($cardsToUpdate, supplier_push_wb_card_update_batch_size()) as $idx => $chunk) {
        supplier_push_wb_remap_update_cards_resilient(
            $client,
            $chunk,
            $idx + 1,
            $opId,
            $log,
            $stats,
            $workDone,
            $total,
            $overallTotal,
            $onSuccess
        );
    }

    ops_update_progress($opId, $overallTotal, $overallTotal, 'done', 'WB артикулы: отправка завершена');
    return $stats;
}

function supplier_push_wb_create_cards_resilient(
    WildberriesClient $client,
    array $cards,
    int $batchNo,
    int $opId,
    callable $log,
    array &$stats,
    int &$workDone,
    int $prepareTotal,
    int $overallTotal,
    int $depth = 0
): void {
    $count = count($cards);
    if ($count <= 0) {
        return;
    }

    $progress = static function (string $detail = '') use ($opId, &$stats, &$workDone, $prepareTotal, $overallTotal): void {
        $msg = sprintf(
            'WB create: обработано %d; создано %d; пропуски %d',
            $workDone,
            (int)($stats['cards_created'] ?? 0),
            count((array)($stats['skipped'] ?? []))
        );
        if ($detail !== '') {
            $msg .= '; ' . $detail;
        }
        ops_update_progress($opId, min($prepareTotal + $workDone, $overallTotal), max(1, $overallTotal), 'wb_create', $msg);
    };

    try {
        $log('[wb create] batch ' . $batchNo . ($depth > 0 ? '.' . $depth : '') . ': items=' . $count . "\n");
        $resp = $client->createCards($cards);
        $stats['cards_created'] += $count;
        $workDone += $count;
        $stats['responses'][] = [
            'endpoint' => '/content/v2/cards/upload',
            'batch' => $batchNo,
            'count' => $count,
            'response' => $resp,
        ];
        $progress('batch ' . $batchNo);
        return;
    } catch (Throwable $e) {
        if ($count <= 1 || !supplier_push_wb_error_should_split($e)) {
            $label = supplier_push_wb_card_vendor_label((array)$cards[0]);
            $rangeLabel = $count <= 1 ? $label : ('пачку ' . $batchNo . ' (' . $count . ' товаров)');
            $stats['skipped'][] = 'WB create: не удалось создать ' . $rangeLabel . ': ' . $e->getMessage();
            $stats['responses'][] = [
                'endpoint' => '/content/v2/cards/upload',
                'vendor_code' => $count <= 1 ? $label : null,
                'batch' => $batchNo,
                'count' => $count,
                'error' => $e->getMessage(),
            ];
            $workDone += $count;
            $log('[wb create] batch ' . $batchNo . ': '
                . ($count <= 1 ? ('item error ' . $label) : 'batch error without split')
                . ': ' . $e->getMessage() . "\n");
            $progress($count <= 1 ? $label : ('batch ' . $batchNo));
            return;
        }

        $log('[wb create] batch ' . $batchNo . ': error, split ' . $count . ' items: ' . $e->getMessage() . "\n");
        $mid = max(1, intdiv($count, 2));
        supplier_push_wb_create_cards_resilient(
            $client,
            array_slice($cards, 0, $mid),
            $batchNo,
            $opId,
            $log,
            $stats,
            $workDone,
            $prepareTotal,
            $overallTotal,
            $depth + 1
        );
        supplier_push_wb_create_cards_resilient(
            $client,
            array_slice($cards, $mid),
            $batchNo,
            $opId,
            $log,
            $stats,
            $workDone,
            $prepareTotal,
            $overallTotal,
            $depth + 1
        );
    }
}

function supplier_push_wb_dimensions_only(
    array $cfg,
    int $datasetId,
    int $opId,
    array $bundles,
    WildberriesClient $client,
    callable $log,
    array $stats
): array {
    $total = count($bundles);
    $bundleByVendorCode = [];
    foreach ($bundles as $bundle) {
        $vendorCode = supplier_push_wb_vendor_code((array)$bundle);
        if ($vendorCode === '') {
            $stats['skipped'][] = 'WB dimensions: товар без vendorCode/offer_id';
            continue;
        }
        if (!isset($bundleByVendorCode[$vendorCode])) {
            $bundleByVendorCode[$vendorCode] = $bundle;
        }
    }

    $log('WB dimensions fast path: products=' . $total . '; vendor_codes=' . count($bundleByVendorCode) . "\n");
    ops_update_progress($opId, 0, max(1, $total * 2), 'wb_fetch_cards', 'WB размеры: загружаем карточки WB пачками');
    $cardsByVendorCode = supplier_push_wb_fetch_cards_by_vendor_codes(
        $client,
        array_keys($bundleByVendorCode),
        $opId,
        $log
    );

    $cardsToUpdate = [];
    $prepareDone = 0;
    foreach ($bundleByVendorCode as $vendorCode => $bundle) {
        $prepareDone++;
        $card = is_array($cardsByVendorCode[$vendorCode] ?? null) ? (array)$cardsByVendorCode[$vendorCode] : null;
        if ($card === null) {
            $stats['skipped'][] = 'WB dimensions: карточка не найдена ' . $vendorCode;
            continue;
        }

        $dims = supplier_push_dimensions((array)$bundle);
        $cm = is_array($dims['cm'] ?? null) ? $dims['cm'] : null;
        $weightKg = supplier_push_weight_kg((string)(((array)($bundle['standard'] ?? []))['weight'] ?? ''));
        if (!is_array($cm) || $cm[0] === null || $cm[1] === null || $cm[2] === null || $weightKg === null) {
            $stats['skipped'][] = 'WB dimensions: не хватает размеров или веса для ' . $vendorCode;
            continue;
        }

        $dimensionsPayload = supplier_push_wb_dimensions_payload([
            'length' => $cm[0],
            'width' => $cm[1],
            'height' => $cm[2],
            'weightBrutto' => $weightKg,
        ]);
        if ($dimensionsPayload === null) {
            $stats['skipped'][] = 'WB dimensions: некорректные размеры или вес для ' . $vendorCode;
            continue;
        }

        $card['dimensions'] = $dimensionsPayload;
        $cardsToUpdate[] = supplier_push_wb_prepare_update_card($card);

        if ($prepareDone === 1 || ($prepareDone % 50) === 0 || $prepareDone === count($bundleByVendorCode)) {
            ops_update_progress(
                $opId,
                min($prepareDone, max(1, $total)),
                max(1, $total * 2),
                'wb_prepare_dimensions',
                'WB размеры: подготовлено ' . $prepareDone . '/' . count($bundleByVendorCode) . '; к отправке ' . count($cardsToUpdate)
            );
        }
    }

    $workTotal = max(1, count($cardsToUpdate));
    $overallTotal = max(1, count($bundleByVendorCode) + $workTotal);
    $workDone = 0;
    $log('WB dimensions prepared: update=' . count($cardsToUpdate) . '; skipped=' . count((array)($stats['skipped'] ?? [])) . "\n");
    ops_update_progress($opId, min(count($bundleByVendorCode), $overallTotal), $overallTotal, 'wb_ready', 'WB размеры: готово к отправке ' . count($cardsToUpdate));

    $batchSize = supplier_push_wb_card_update_batch_size();
    foreach (array_chunk($cardsToUpdate, $batchSize) as $idx => $chunk) {
        supplier_push_wb_update_cards_resilient(
            $client,
            $chunk,
            $idx + 1,
            $opId,
            $log,
            $stats,
            $workDone,
            count($bundleByVendorCode),
            $overallTotal
        );
    }

    return supplier_push_report($cfg, $datasetId, $opId, $stats);
}

function supplier_push_wb(array $cfg, int $datasetId, int $supplierId, int $opId, array $params, callable $log, array $fields): array
{
    [$supplier, $bundles] = supplier_push_load_bundles($supplierId, $params);
    $connectionId = (int)($params['connection_id'] ?? 0);
    $connection = ozon_price_connection_get($connectionId, $cfg);
    if (!is_array($connection) || (string)($connection['marketplace'] ?? '') !== 'wb') {
        throw new RuntimeException('Выбери подключение Wildberries.');
    }

    $client = wb_price_tool_client($cfg, $connection);
    $client->setRetryLogger(static function (int $attempt, int $delaySec, string $method, string $api, string $path) use ($log): void {
        $log("WB limiter: {$method} {$api}{$path}, пауза {$delaySec} сек перед повтором #" . ($attempt + 1) . "\n");
    });
    $createdPhotoWaitSec = max(0, min(90, (int)($params['wb_created_photo_wait_sec'] ?? 0)));
    $createdPhotoWaitIntervalSec = max(1, min(10, (int)($params['wb_created_photo_wait_interval_sec'] ?? 3)));
    $total = count($bundles);
    $enabledFieldNames = supplier_push_enabled_field_names($fields);
    $fullCardRequested = supplier_push_all_content_fields_selected($fields);
    $log("supplier_db push_marketplace_content: marketplace=wb, connection_id={$connectionId}, products={$total}\n");
    $log('WB selected fields: ' . implode(', ', $enabledFieldNames) . "\n");
    ops_update_progress($opId, 0, max(1, $total * 2), 'wb_prepare', 'WB подготовка: проверяем карточки перед отправкой');

    $stats = [
        'marketplace' => 'wb',
        'products_seen' => $total,
        'selected_fields' => $enabledFieldNames,
        'full_card_requested' => $fullCardRequested,
        'cards_sent' => 0,
        'cards_created' => 0,
        'descriptions_prepared' => 0,
        'descriptions_created' => 0,
        'media_sent' => 0,
        'photos_sent' => 0,
        'video_sent' => 0,
        'skipped' => [],
        'responses' => [],
    ];

    if (supplier_push_only_content_field($fields, 'dimensions')) {
        return supplier_push_wb_dimensions_only($cfg, $datasetId, $opId, $bundles, $client, $log, $stats);
    }

    $vendorCodes = [];
    foreach ($bundles as $bundle) {
        $vendorCode = supplier_push_wb_vendor_code((array)$bundle);
        if ($vendorCode !== '') {
            $vendorCodes[$vendorCode] = true;
        }
    }
    $cardsByVendorCode = [];
    if ($vendorCodes) {
        try {
            $log('WB cards bulk preload: vendor_codes=' . count($vendorCodes) . "\n");
            $cardsByVendorCode = supplier_push_wb_fetch_existing_cards_for_push(
                $client,
                array_keys($vendorCodes),
                $opId,
                $log
            );
            $log('WB cards bulk preload done: found=' . count($cardsByVendorCode) . '/' . count($vendorCodes) . "\n");
        } catch (Throwable $e) {
            throw new RuntimeException('WB: не удалось быстро загрузить список карточек: ' . $e->getMessage(), 0, $e);
        }
    }

    $cardsToUpdate = [];
    $cardsToCreate = [];
    $createdPhotoJobs = [];
    $createBundles = [];
    $mediaJobs = [];
    $prepareDone = 0;
    $prepareTotal = max(1, $total);
    $lastPrepareProgressAt = 0.0;
    $lastPrepareLogAt = 0.0;
    $updatePrepareProgress = static function (bool $force = false, string $vendorCode = '') use (
        $opId,
        $log,
        $prepareTotal,
        &$prepareDone,
        &$cardsToUpdate,
        &$cardsToCreate,
        &$createBundles,
        &$mediaJobs,
        &$createdPhotoJobs,
        &$stats,
        &$lastPrepareProgressAt,
        &$lastPrepareLogAt
    ): void {
        $now = microtime(true);
        $shouldUpdate = $force || $prepareDone <= 1 || ($prepareDone % 25) === 0 || (($now - $lastPrepareProgressAt) >= 5.0);
        if (!$shouldUpdate) {
            return;
        }

        $msg = sprintf(
            'WB подготовка: проверено %d/%d; к обновлению %d; к созданию %d; медиа %d; пропуски %d',
            min($prepareDone, $prepareTotal),
            $prepareTotal,
            count($cardsToUpdate),
            count($cardsToCreate) + count($createBundles),
            count($mediaJobs) + count($createdPhotoJobs),
            count((array)($stats['skipped'] ?? []))
        );
        if ($vendorCode !== '') {
            $msg .= '; сейчас ' . $vendorCode;
        }

        ops_update_progress($opId, min($prepareDone, $prepareTotal), max(1, $prepareTotal * 2), 'wb_prepare', $msg);

        $shouldLog = $force || $prepareDone <= 1 || $prepareDone === $prepareTotal || (($now - $lastPrepareLogAt) >= 30.0);
        if ($shouldLog) {
            $log($msg . "\n");
            $lastPrepareLogAt = $now;
        }
        $lastPrepareProgressAt = $now;
    };

    foreach ($bundles as $bundle) {
        $vendorCode = supplier_push_wb_vendor_code($bundle);
        $prepareDone++;
        if ($vendorCode === '') {
            $stats['skipped'][] = 'WB: товар без vendorCode/offer_id';
            $updatePrepareProgress(false, $vendorCode);
            continue;
        }
        $card = is_array($cardsByVendorCode[$vendorCode] ?? null) ? (array)$cardsByVendorCode[$vendorCode] : null;
        if (!is_array($card)) {
            if (!$fullCardRequested) {
                $stats['skipped'][] = 'WB: карточка ' . $vendorCode . ' не найдена; частичное обновление (' . implode(', ', $enabledFieldNames) . ') не отправлено, чтобы не создать новую карточку.';
                $updatePrepareProgress(false, $vendorCode);
                continue;
            }
            $createBundles[] = [
                'bundle' => $bundle,
                'vendor_code' => $vendorCode,
            ];
            $updatePrepareProgress(false, $vendorCode);
            continue;
        }

        $changedCard = false;
        if (!empty($fields['name']) && trim((string)($bundle['name'] ?? '')) !== '') {
            $title = supplier_push_wb_title_for_payload($bundle, supplier_push_wb_title_brand($bundle, $card), $vendorCode);
            if ($title !== '' && !supplier_push_trimmed_equal($title, $card['title'] ?? '')) {
                $card['title'] = $title;
                $changedCard = true;
            }
        }
        if (!empty($fields['brand']) && (trim((string)($bundle['brand_wb'] ?? '')) !== '' || trim((string)($bundle['brand'] ?? '')) !== '')) {
            $brandSkipped = [];
            $brand = supplier_push_wb_brand_for_payload($client, $bundle, $card, $vendorCode, $brandSkipped);
            foreach ($brandSkipped as $skip) {
                $stats['skipped'][] = $skip;
            }
            if (!supplier_push_trimmed_equal($brand, $card['brand'] ?? '')) {
                $card['brand'] = $brand;
                $changedCard = true;
            }
        }
        if (!empty($fields['description']) && trim((string)($bundle['description'] ?? '')) !== '') {
            $description = supplier_push_wb_description_for_payload((string)$bundle['description'], (string)($card['title'] ?? ''));
            if (!supplier_push_trimmed_equal($description, $card['description'] ?? '')) {
                $card['description'] = $description;
                $stats['descriptions_prepared']++;
                $changedCard = true;
            }
        } elseif (!empty($fields['description'])) {
            $stats['skipped'][] = 'WB description: нет описания для ' . $vendorCode;
        }
        if (!empty($fields['dimensions'])) {
            $dims = supplier_push_dimensions($bundle);
            $cm = is_array($dims['cm'] ?? null) ? $dims['cm'] : null;
            $weightKg = supplier_push_weight_kg((string)(((array)($bundle['standard'] ?? []))['weight'] ?? ''));
            if (is_array($cm) && $cm[0] !== null && $cm[1] !== null && $cm[2] !== null && $weightKg !== null) {
                $dimensionsPayload = supplier_push_wb_dimensions_payload([
                    'length' => $cm[0],
                    'width' => $cm[1],
                    'height' => $cm[2],
                    'weightBrutto' => $weightKg,
                ]);
                if ($dimensionsPayload !== null) {
                    if (!supplier_push_wb_dimensions_equal((array)($card['dimensions'] ?? []), $dimensionsPayload)) {
                        $card['dimensions'] = $dimensionsPayload;
                        $changedCard = true;
                    }
                } else {
                    $stats['skipped'][] = 'WB: некорректные размеры или вес для ' . $vendorCode;
                }
            } else {
                $stats['skipped'][] = 'WB: не хватает размеров или веса для ' . $vendorCode;
            }
        }
        if (!empty($fields['model']) || !empty($fields['characteristics']) || !empty($fields['tnved'])) {
            $nextChars = supplier_push_wb_values_for_characteristics($bundle, $fields, $card);
            if ($nextChars) {
                $beforeChars = supplier_push_wb_characteristics_signature($card['characteristics'] ?? []);
                $mergedCard = supplier_push_wb_merge_characteristics($card, $nextChars);
                $afterChars = supplier_push_wb_characteristics_signature($mergedCard['characteristics'] ?? []);
                if ($beforeChars !== $afterChars) {
                    $card = $mergedCard;
                    $changedCard = true;
                }
            } elseif (!empty($fields['model']) || !empty($fields['characteristics']) || !empty($fields['tnved'])) {
                $stats['skipped'][] = 'WB: не найдены подходящие ID характеристик для ' . $vendorCode;
            }
        }
        if ($changedCard) {
            $cardsToUpdate[$vendorCode] = supplier_push_wb_prepare_update_card($card);
        }
        if (!empty($fields['photos']) || !empty($fields['video'])) {
            $nmId = (int)($card['nmID'] ?? 0);
            $hasVideoForBundle = !empty($fields['video']) && supplier_push_video_cover_url($bundle) !== '';
            if (empty($fields['photos']) && !empty($fields['video']) && !$hasVideoForBundle) {
                $stats['skipped'][] = 'WB video: нет видео-обложки для ' . $vendorCode;
                $updatePrepareProgress(false, $vendorCode);
                continue;
            }
            $mediaUrls = supplier_push_wb_media_urls_for_payload($bundle, $cfg, !empty($fields['photos']), !empty($fields['video']), $card);
            if ($nmId > 0 && $mediaUrls) {
                $mediaJobs[] = [
                    'nm_id' => $nmId,
                    'vendor_code' => $vendorCode,
                    'pictures' => array_values($mediaUrls),
                    'has_photos' => !empty($fields['photos']),
                    'has_video' => $hasVideoForBundle,
                ];
            } else {
                $stats['skipped'][] = 'WB media: нет nmID или медиа для ' . $vendorCode;
            }
        }
        $updatePrepareProgress(false, $vendorCode);
    }
    $updatePrepareProgress(true);

    if ($createBundles) {
        $createVendorCodes = [];
        foreach ($createBundles as $entry) {
            $vendorCode = trim((string)($entry['vendor_code'] ?? ''));
            if ($vendorCode !== '') {
                $createVendorCodes[$vendorCode] = true;
            }
        }

        $log('WB create preflight: точечно проверяем кандидатов на создание, count=' . count($createVendorCodes) . "\n");
        $existingCreateCards = supplier_push_wb_fetch_cards_by_vendor_codes_exact(
            $client,
            array_keys($createVendorCodes),
            $opId,
            $log,
            'WB create preflight'
        );

        if ($existingCreateCards) {
            $remainingCreateBundles = [];
            foreach ($createBundles as $entry) {
                $bundle = (array)($entry['bundle'] ?? []);
                $vendorCode = trim((string)($entry['vendor_code'] ?? ''));
                $card = is_array($existingCreateCards[$vendorCode] ?? null) ? (array)$existingCreateCards[$vendorCode] : null;
                if ($vendorCode === '' || !is_array($card)) {
                    $remainingCreateBundles[] = $entry;
                    continue;
                }

                $preparedExisting = supplier_push_wb_prepare_existing_card_for_bundle(
                    $client,
                    $cfg,
                    $bundle,
                    $card,
                    $vendorCode,
                    $fields,
                    $stats
                );
                if (is_array($preparedExisting['update_card'] ?? null)) {
                    $cardsToUpdate[$vendorCode] = (array)$preparedExisting['update_card'];
                }
                if (is_array($preparedExisting['media_job'] ?? null)) {
                    $mediaJobs[] = (array)$preparedExisting['media_job'];
                }
            }
            $movedToUpdate = count($createBundles) - count($remainingCreateBundles);
            $createBundles = $remainingCreateBundles;
            $log('WB create preflight done: moved_to_update=' . $movedToUpdate . '; still_create=' . count($createBundles) . "\n");
            $updatePrepareProgress(true);
        } else {
            $log("WB create preflight done: existing cards not found\n");
        }
    }

    $generatedBarcodes = supplier_push_wb_generate_unique_barcodes($client, count($createBundles), $stats['skipped']);

    foreach ($createBundles as $entry) {
        $bundle = (array)$entry['bundle'];
        $vendorCode = (string)$entry['vendor_code'];
        ops_update_progress(
            $opId,
            min($prepareDone, $prepareTotal),
            max(1, $prepareTotal * 2),
            'wb_prepare_create',
            'WB подготовка новых карточек: ' . (count($cardsToCreate) + 1) . '/' . max(1, count($createBundles)) . '; ' . $vendorCode
        );
        $barcode = $generatedBarcodes ? array_shift($generatedBarcodes) : '';
        $createSkipped = [];
        $brandSkipped = [];
        $payloadBrand = supplier_push_wb_brand_for_payload($client, $bundle, null, $vendorCode, $brandSkipped);
        foreach ($brandSkipped as $skip) {
            $stats['skipped'][] = $skip;
        }
        $cardToCreate = supplier_push_wb_build_create_card($bundle, $vendorCode, $barcode, $createSkipped, $payloadBrand);
        foreach ($createSkipped as $skip) {
            $stats['skipped'][] = $skip;
        }
        if ($cardToCreate) {
            $cardsToCreate[] = $cardToCreate;
            if (trim((string)($bundle['description'] ?? '')) !== '') {
                $stats['descriptions_created']++;
            }
            $hasVideoForBundle = !empty($fields['video']) && supplier_push_video_cover_url($bundle) !== '';
            if (empty($fields['photos']) && !empty($fields['video']) && !$hasVideoForBundle) {
                $stats['skipped'][] = 'WB create video: нет видео-обложки для ' . $vendorCode;
                continue;
            }
            $bundlePictures = supplier_push_wb_media_urls_for_payload($bundle, $cfg, !empty($fields['photos']), !empty($fields['video']));
            if ((!empty($fields['photos']) || !empty($fields['video'])) && $bundlePictures) {
                $createdPhotoJobs[] = [
                    'vendor_code' => $vendorCode,
                    'pictures' => array_values($bundlePictures),
                    'has_photos' => !empty($fields['photos']),
                    'has_video' => $hasVideoForBundle,
                ];
            }
        }
    }

    $workTotal = max(1, count($cardsToUpdate) + count($cardsToCreate) + count($mediaJobs) + count($createdPhotoJobs));
    $overallTotal = max(1, $prepareTotal + $workTotal);
    $workDone = 0;
    $updateWorkProgress = static function (string $stage, string $phase, string $detail = '') use (
        $opId,
        $prepareTotal,
        $overallTotal,
        $workTotal,
        &$workDone,
        &$stats
    ): void {
        $msg = sprintf(
            '%s: отправлено %d/%d; обновлено %d; создано %d; медиа %d; пропуски %d',
            $phase,
            min($workDone, $workTotal),
            $workTotal,
            (int)($stats['cards_sent'] ?? 0),
            (int)($stats['cards_created'] ?? 0),
            (int)($stats['media_sent'] ?? $stats['photos_sent'] ?? 0),
            count((array)($stats['skipped'] ?? []))
        );
        if ($detail !== '') {
            $msg .= '; ' . $detail;
        }
        ops_update_progress($opId, min($prepareTotal + $workDone, $overallTotal), $overallTotal, $stage, $msg);
    };

    $log(
        "WB prepared: products={$total}; update=" . count($cardsToUpdate)
        . "; create=" . count($cardsToCreate)
        . "; descriptions_update=" . (int)($stats['descriptions_prepared'] ?? 0)
        . "; descriptions_create=" . (int)($stats['descriptions_created'] ?? 0)
        . "; existing_media=" . count($mediaJobs)
        . "; created_media=" . count($createdPhotoJobs)
        . "; skipped=" . count((array)($stats['skipped'] ?? []))
        . "\n"
    );
    $updateWorkProgress('wb_ready', 'WB готово к отправке');

    foreach (array_chunk($cardsToCreate, supplier_push_wb_card_create_batch_size()) as $idx => $chunk) {
        supplier_push_wb_create_cards_resilient(
            $client,
            $chunk,
            $idx + 1,
            $opId,
            $log,
            $stats,
            $workDone,
            $prepareTotal,
            $overallTotal
        );
    }

    foreach ($createdPhotoJobs as $job) {
        $vendorCode = (string)$job['vendor_code'];
        try {
            if ($createdPhotoWaitSec > 0) {
                $updateWorkProgress('wb_wait_media', 'WB ждём nmID для медиа', $vendorCode);
                $card = supplier_push_wb_wait_for_created_card($client, $vendorCode, $createdPhotoWaitSec, $createdPhotoWaitIntervalSec);
                $nmId = is_array($card) ? (int)($card['nmID'] ?? 0) : 0;
                if ($nmId > 0) {
                    $resp = $client->saveMediaLinks($nmId, (array)$job['pictures']);
                    $stats['media_sent']++;
                    if (!empty($job['has_photos'])) $stats['photos_sent']++;
                    if (!empty($job['has_video'])) $stats['video_sent']++;
                    $stats['responses'][] = [
                        'endpoint' => '/content/v3/media/save',
                        'vendor_code' => $vendorCode,
                        'nm_id' => $nmId,
                        'pictures' => count((array)$job['pictures']),
                        'after_create' => true,
                        'response' => $resp,
                    ];
                } else {
                    $stats['skipped'][] = 'WB create media: карточка ' . $vendorCode . ' создана асинхронно, но nmID не появился за ' . $createdPhotoWaitSec . ' секунд; повтори отправку медиа после синхронизации WB.';
                }
            } else {
                $stats['skipped'][] = 'WB create: медиа для ' . $vendorCode . ' можно отправить после синхронизации WB, когда появится nmID.';
            }
        } catch (Throwable $e) {
            $stats['skipped'][] = 'WB create media: не удалось отправить медиа для ' . $vendorCode . ': ' . $e->getMessage();
            $stats['responses'][] = [
                'endpoint' => '/content/v3/media/save',
                'vendor_code' => $vendorCode,
                'pictures' => count((array)$job['pictures']),
                'after_create' => true,
                'error' => $e->getMessage(),
            ];
        }
        $workDone++;
        $updateWorkProgress('wb_media', 'WB media', $vendorCode);
    }

    foreach (array_chunk($cardsToUpdate, supplier_push_wb_card_update_batch_size()) as $idx => $chunk) {
        supplier_push_wb_update_cards_resilient(
            $client,
            $chunk,
            $idx + 1,
            $opId,
            $log,
            $stats,
            $workDone,
            $prepareTotal,
            $overallTotal
        );
    }

    foreach ($mediaJobs as $job) {
        $vendorCode = (string)$job['vendor_code'];
        $nmId = (int)$job['nm_id'];
        try {
            $resp = $client->saveMediaLinks($nmId, (array)$job['pictures']);
            $stats['media_sent']++;
            if (!empty($job['has_photos'])) $stats['photos_sent']++;
            if (!empty($job['has_video'])) $stats['video_sent']++;
            $stats['responses'][] = [
                'endpoint' => '/content/v3/media/save',
                'vendor_code' => $vendorCode,
                'nm_id' => $nmId,
                'pictures' => count((array)$job['pictures']),
                'response' => $resp,
            ];
        } catch (Throwable $e) {
            $stats['skipped'][] = 'WB media: не удалось отправить медиа для ' . $vendorCode . ': ' . $e->getMessage();
            $stats['responses'][] = [
                'endpoint' => '/content/v3/media/save',
                'vendor_code' => $vendorCode,
                'nm_id' => $nmId,
                'pictures' => count((array)$job['pictures']),
                'error' => $e->getMessage(),
            ];
        }
        $workDone++;
        $updateWorkProgress('wb_media', 'WB media', $vendorCode);
    }

    return supplier_push_report($cfg, $datasetId, $opId, $stats);
}

function supplier_push_report(array $cfg, int $datasetId, int $opId, array $stats): array
{
    $outDir = op_output_dir($cfg, $datasetId, $opId);
    ensure_dir($outDir);
    $reportAbs = $outDir . '/marketplace_push_report.json';
    file_put_contents($reportAbs, json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    $marketplace = (string)($stats['marketplace'] ?? '');
    $items = [
        'Маркетплейс: ' . ($marketplace === 'wb' ? 'Wildberries' : 'Ozon'),
        'Товаров выбрано: ' . (int)($stats['products_seen'] ?? 0),
    ];
    if ($marketplace === 'wb') {
        $items[] = 'Карточек отправлено: ' . (int)($stats['cards_sent'] ?? 0);
        $items[] = 'Новых карточек отправлено на создание: ' . (int)($stats['cards_created'] ?? 0);
        $items[] = 'Описаний подготовлено для обновления: ' . (int)($stats['descriptions_prepared'] ?? 0);
        $items[] = 'Описаний подготовлено для новых карточек: ' . (int)($stats['descriptions_created'] ?? 0);
        $items[] = 'Media-запросов отправлено: ' . (int)($stats['media_sent'] ?? $stats['photos_sent'] ?? 0);
        $items[] = 'Фото отправлено: ' . (int)($stats['photos_sent'] ?? 0);
        $items[] = 'Видео отправлено: ' . (int)($stats['video_sent'] ?? 0);
        if ((int)($stats['cards_created'] ?? 0) > 0) {
            $items[] = 'WB принял новые карточки в очередь. Появление в кабинете может занять до 30 минут.';
        }
    } else {
        $importLabel = !empty($stats['full_card_requested'])
            ? 'Полных карточек отправлено'
            : 'Обновлений карточек через Ozon import отправлено';
        $items[] = $importLabel . ': ' . (int)($stats['import_items_sent'] ?? 0);
        $items[] = 'Размеров/веса отправлено: ' . (int)($stats['dimension_items_sent'] ?? 0);
        $items[] = 'Новых карточек отправлено на создание: ' . (int)($stats['created_items_sent'] ?? 0);
        $items[] = 'Наборов характеристик отправлено: ' . (int)($stats['attribute_items_sent'] ?? 0);
        $items[] = 'Фото отправлено: ' . (int)($stats['photos_items_sent'] ?? 0);
        if ((int)($stats['ozon_photo_webp_converted'] ?? 0) > 0) {
            $items[] = 'WebP/GIF-фото подготовлено как JPEG для Ozon: ' . (int)$stats['ozon_photo_webp_converted'];
        }
        if ((int)($stats['ozon_photo_external_mirrored'] ?? 0) > 0) {
            $items[] = 'Внешних фото зеркалировано как JPEG для Ozon: ' . (int)$stats['ozon_photo_external_mirrored'];
        }
        if ((int)($stats['ozon_photo_fallback_sent'] ?? 0) > 0) {
            $items[] = 'Фото Ozon отправлено fallback-набором: ' . (int)$stats['ozon_photo_fallback_sent'];
        }
    }
    if (!empty($stats['skipped'])) {
        $items[] = 'Пропуски/предупреждения: ' . count((array)$stats['skipped']);
        foreach (array_slice((array)$stats['skipped'], 0, 3) as $skip) {
            $items[] = 'Предупреждение: ' . (string)$skip;
        }
    }
    $responseErrorsCount = 0;
    foreach ((array)($stats['responses'] ?? []) as $response) {
        if (is_array($response) && !empty($response['error'])) {
            $responseErrorsCount++;
        }
    }
    $errorsCount = max((int)($stats['ozon_send_errors'] ?? 0), $responseErrorsCount)
        + (int)($stats['ozon_task_errors'] ?? 0);
    if ($errorsCount > 0) {
        $items[] = 'Ошибки отправки/проверки: ' . $errorsCount;
        foreach ((array)($stats['responses'] ?? []) as $response) {
            if (is_array($response) && !empty($response['error'])) {
                $items[] = 'Ошибка: ' . (string)$response['error'];
                if (count($items) >= 12) {
                    break;
                }
            }
        }
    }

    ops_update_progress(
        $opId,
        (int)($stats['products_seen'] ?? 0),
        max(1, (int)($stats['products_seen'] ?? 0)),
        'done',
        $errorsCount > 0 ? 'Done with warnings/errors' : 'Done'
    );

    return [
        'marketplace_push_report_json' => rel_to_outputs($cfg, $reportAbs),
        'summary_json_inline' => [
            'title' => 'Отправка товаров на маркетплейс',
            'items' => $items,
            'metrics' => $stats,
        ],
    ];
}

function supplier_products_db_op_push_marketplace_content(array $cfg, array $ds, int $opId, array $params, callable $log, string $marketplace): array
{
    $datasetId = (int)($ds['id'] ?? 0);
    $supplierId = supplier_products_supplier_id_for_dataset($datasetId, $cfg);
    if ($supplierId <= 0) {
        throw new RuntimeException('DB-датасет поставщика не найден.');
    }
    $fields = supplier_push_selected_fields($params);
    if ($marketplace === 'ozon') {
        return supplier_push_ozon($cfg, $datasetId, $supplierId, $opId, $params, $log, $fields);
    }
    if ($marketplace === 'wb') {
        return supplier_push_wb($cfg, $datasetId, $supplierId, $opId, $params, $log, $fields);
    }
    throw new RuntimeException('Неподдерживаемый маркетплейс для отправки.');
}
