<?php
declare(strict_types=1);

function ft_card_brief_plain_text(string $value, int $limit = 800): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('~<\s*br\s*/?\s*>~iu', "\n", $value) ?? $value;
    $value = trim((string)preg_replace('~\s+~u', ' ', strip_tags($value)));
    if ($limit > 0 && mb_strlen($value, 'UTF-8') > $limit) {
        $value = mb_substr($value, 0, $limit, 'UTF-8') . '...';
    }
    return $value;
}

function ft_card_brief_clip(string $value, int $limit = 180): string
{
    $value = trim((string)preg_replace('~\s+~u', ' ', $value));
    if ($limit > 0 && mb_strlen($value, 'UTF-8') > $limit) {
        $value = rtrim((string)mb_substr($value, 0, $limit, 'UTF-8'), " \t\n\r\0\x0B,.;:-") . '...';
    }
    return $value;
}

function ft_card_brief_lc(string $value): string
{
    return mb_strtolower($value, 'UTF-8');
}

function ft_card_brief_contains_any(string $haystack, array $needles): bool
{
    $haystack = ft_card_brief_lc($haystack);
    foreach ($needles as $needle) {
        $needle = ft_card_brief_lc((string)$needle);
        if ($needle !== '' && str_contains($haystack, $needle)) {
            return true;
        }
    }
    return false;
}

function ft_card_brief_unique_push(array &$target, string $value, int $limit = 12): void
{
    $value = ft_card_brief_clip($value, 220);
    if ($value === '') {
        return;
    }
    $key = ft_card_brief_lc($value);
    foreach ($target as $existing) {
        if (ft_card_brief_lc((string)$existing) === $key) {
            return;
        }
    }
    if (count($target) < $limit) {
        $target[] = $value;
    }
}

function ft_card_brief_keyword_lines($value, int $limit = 12): array
{
    if (is_array($value)) {
        $value = implode("\n", array_map('strval', $value));
    }
    $value = trim((string)$value);
    if ($value === '') {
        return [];
    }
    $parts = preg_split('~[\r\n;]+~u', $value) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '') {
            continue;
        }
        foreach (preg_split('~\s*,\s*~u', $part) ?: [] as $candidate) {
            $candidate = trim((string)$candidate);
            $candidate = preg_replace('~^\d+[\).:\-]\s*~u', '', $candidate) ?? $candidate;
            $candidate = ft_card_brief_clip($candidate, 80);
            if ($candidate !== '') {
                ft_card_brief_unique_push($out, $candidate, $limit);
            }
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function ft_card_brief_params(array $product): array
{
    $params = [];
    foreach ((array)($product['params'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = ft_card_brief_clip((string)($row['name'] ?? ''), 80);
        $value = ft_card_brief_clip((string)($row['value'] ?? ''), 140);
        if ($name === '' || $value === '') {
            continue;
        }
        $params[] = [
            'name' => $name,
            'value' => $value,
            'marketplace' => trim((string)($row['marketplace'] ?? '')),
        ];
    }
    return $params;
}

function ft_card_brief_fact_label(array $param): string
{
    return ft_card_brief_clip((string)($param['name'] ?? '') . ': ' . (string)($param['value'] ?? ''), 180);
}

function ft_card_brief_params_by_name(array $params, array $needles, int $limit = 8): array
{
    $out = [];
    foreach ($params as $param) {
        $name = (string)($param['name'] ?? '');
        if (ft_card_brief_contains_any($name, $needles)) {
            ft_card_brief_unique_push($out, ft_card_brief_fact_label($param), $limit);
        }
    }
    return $out;
}

function ft_card_brief_leaf_name(array $category): string
{
    $name = ft_card_brief_clip((string)($category['name'] ?? ''), 100);
    if ($name !== '') {
        return $name;
    }
    $path = ft_card_brief_clip((string)($category['full_path'] ?? ''), 220);
    if ($path !== '') {
        $parts = preg_split('~\s*(?:>|/|→)\s*~u', $path) ?: [];
        $last = trim((string)end($parts));
        if ($last !== '') {
            return ft_card_brief_clip($last, 100);
        }
    }
    return '';
}

function ft_card_brief_buyer_task(string $haystack, string $productType): string
{
    if (ft_card_brief_contains_any($haystack, ['аккумулятор', 'батарея', 'battery'])) {
        return 'покупатель выбирает совместимый аккумулятор и проверяет модель, емкость, размер и надежность замены';
    }
    if (ft_card_brief_contains_any($haystack, ['дисплей', 'экран', 'тачскрин', 'матрица'])) {
        return 'покупатель ищет экранную запчасть и хочет быстро проверить совместимость, цвет, комплектность и состояние детали';
    }
    if (ft_card_brief_contains_any($haystack, ['шлейф', 'плата', 'разъем', 'модуль', 'корпус', 'крышк', 'динамик', 'камера'])) {
        return 'покупатель подбирает запчасть для ремонта и должен понять тип детали, совместимость и ключевые отличия модификации';
    }
    if (ft_card_brief_contains_any($haystack, ['запчаст', 'ремонт', 'совместим'])) {
        return 'покупатель ищет совместимую деталь и проверяет, подойдет ли она к нужному устройству или модели';
    }
    if ($productType !== '') {
        return 'покупатель выбирает товар типа "' . $productType . '" и сравнивает назначение, характеристики и применимость';
    }
    return 'покупатель должен быстро понять, что это за товар, для какой задачи он нужен и какие характеристики подтверждены';
}

function ft_card_brief_build(array $product, array $category = [], array $options = []): array
{
    $params = ft_card_brief_params($product);
    $name = ft_card_brief_clip((string)($product['name'] ?? ''), 220);
    $brand = ft_card_brief_clip((string)($product['brand'] ?? ''), 100);
    $vendor = ft_card_brief_clip((string)($product['vendor'] ?? ''), 100);
    $model = ft_card_brief_clip((string)($product['model'] ?? ''), 120);
    $categoryName = ft_card_brief_leaf_name($category);
    $categoryPath = ft_card_brief_clip((string)($category['full_path'] ?? ($product['category_path'] ?? '')), 220);
    $productType = $categoryName !== '' ? $categoryName : ft_card_brief_clip((string)($product['type'] ?? ''), 100);
    $description = ft_card_brief_plain_text((string)(
        ($product['description_existing_plain'] ?? '')
        ?: ($product['description'] ?? '')
        ?: ($product['description_existing_html'] ?? '')
    ), 900);

    $keywordsRaw = $category['keywords_lines'] ?? ($category['keywords'] ?? '');
    if (empty($options['use_keywords'])) {
        $keywordsRaw = '';
    }
    $keywords = ft_card_brief_keyword_lines($keywordsRaw, 14);

    $haystack = implode(' ', array_filter([
        $name,
        $brand,
        $vendor,
        $model,
        $categoryName,
        $categoryPath,
        ft_card_brief_plain_text((string)($category['description'] ?? ''), 500),
        ft_card_brief_plain_text((string)($category['typical_goods'] ?? ''), 500),
        ft_card_brief_plain_text((string)($category['features'] ?? ''), 500),
        $description,
    ]));

    $compatibility = ft_card_brief_params_by_name($params, [
        'совмест', 'подходит', 'для модели', 'модель устройства', 'модель телефона',
        'модель', 'model', 'устройство', 'телефон', 'планшет', 'ноутбук', 'same_model',
    ], 8);
    if ($model !== '') {
        ft_card_brief_unique_push($compatibility, 'Модель/серия: ' . $model, 8);
    }

    $appearance = ft_card_brief_params_by_name($params, ['цвет', 'материал', 'покрытие', 'форма'], 6);
    $dimensions = ft_card_brief_params_by_name($params, ['размер', 'длина', 'ширина', 'высота', 'диаметр', 'толщина', 'габарит', 'вес'], 8);
    $package = ft_card_brief_params_by_name($params, ['комплект', 'количество', 'штук', 'упаков', 'фасов', 'набор'], 6);
    $technical = ft_card_brief_params_by_name($params, [
        'емкость', 'ёмкость', 'напряжение', 'мощность', 'разъем', 'разъём', 'тип',
        'интерфейс', 'диагональ', 'артикул производителя', 'part', 'oem', 'код',
    ], 10);

    $keyFacts = [];
    foreach ([$compatibility, $technical, $dimensions, $appearance, $package] as $group) {
        foreach ($group as $fact) {
            ft_card_brief_unique_push($keyFacts, $fact, 12);
        }
    }
    if (!$keyFacts) {
        foreach (array_slice($params, 0, 10) as $param) {
            ft_card_brief_unique_push($keyFacts, ft_card_brief_fact_label($param), 10);
        }
    }

    $mustPreserve = [];
    foreach ([
        $brand !== '' ? 'Бренд: ' . $brand : '',
        $vendor !== '' && $vendor !== $brand ? 'Производитель/поставщик: ' . $vendor : '',
        $model !== '' ? 'Модель/серия: ' . $model : '',
    ] as $fact) {
        ft_card_brief_unique_push($mustPreserve, $fact, 10);
    }
    foreach (array_slice(array_merge($compatibility, $dimensions, $package), 0, 10) as $fact) {
        ft_card_brief_unique_push($mustPreserve, $fact, 12);
    }

    $buyerQuestions = [];
    ft_card_brief_unique_push($buyerQuestions, 'что это за товар и для какой задачи он нужен', 8);
    if ($compatibility || ft_card_brief_contains_any($haystack, ['совмест', 'для ', 'запчаст', 'ремонт'])) {
        ft_card_brief_unique_push($buyerQuestions, 'подойдет ли товар к нужной модели или сценарию использования', 8);
    }
    if ($dimensions) {
        ft_card_brief_unique_push($buyerQuestions, 'совпадают ли размер, вес или габариты', 8);
    }
    if ($package) {
        ft_card_brief_unique_push($buyerQuestions, 'что входит в комплект и сколько единиц в товаре', 8);
    }
    if ($appearance) {
        ft_card_brief_unique_push($buyerQuestions, 'совпадают ли цвет, материал и внешний вид', 8);
    }
    ft_card_brief_unique_push($buyerQuestions, 'какие характеристики подтверждены входными данными', 8);

    $descriptionFocus = [
        'начать с понятного назначения товара',
        'объяснить совместимость и применение только по подтвержденным данным',
        'органично использовать релевантные ключевые запросы без SEO-спама',
        'не добавлять неподтвержденные свойства, обещания и рекламные оценки',
    ];
    $titleFocus = [
        'сохранить точный тип товара',
        'сохранить важные модели, размеры, количество и коды совместимости',
        'убрать шум, повторы, артикул продавца и лишнюю длину',
        'не менять товарный домен и не придумывать совместимость',
    ];
    $visualPlan = [
        'main_goal' => 'товар должен быть крупно и ясно виден, без рекламных плашек и неподтвержденных деталей',
        'needed_angles' => array_values(array_filter([
            'чистое предметное фото товара',
            $compatibility ? 'кадр, где не теряется форма и элементы, важные для совместимости' : '',
            $dimensions ? 'дополнительный ракурс, помогающий понять размер и пропорции' : '',
            $appearance ? 'ракурс или свет, где хорошо видны цвет и материал' : '',
            $package ? 'кадр комплекта или количества, если это видно и подтверждено исходным фото' : '',
        ])),
        'avoid' => [
            'не добавлять текст, цены, иконки, водяные знаки и маркетплейс-логотипы',
            'не добавлять аксессуары или комплектацию, которых нет на исходном фото',
        ],
    ];

    return [
        '_schema' => 'card_brief_v1',
        'source' => 'deterministic_product_category_context',
        'purpose' => trim((string)($options['purpose'] ?? 'content_generation')),
        'operation_mode' => trim((string)($options['operation_mode'] ?? '')),
        'product_type_hint' => $productType,
        'category_path' => $categoryPath,
        'buyer_task' => ft_card_brief_buyer_task($haystack, $productType),
        'buyer_questions' => $buyerQuestions,
        'must_preserve_facts' => $mustPreserve,
        'key_facts' => $keyFacts,
        'compatibility_facts' => $compatibility,
        'appearance_facts' => $appearance,
        'dimension_facts' => $dimensions,
        'package_facts' => $package,
        'technical_facts' => $technical,
        'buyer_language_keywords' => $keywords,
        'description_focus' => $descriptionFocus,
        'title_focus' => $titleFocus,
        'visual_plan' => $visualPlan,
    ];
}
