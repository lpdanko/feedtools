<?php

declare(strict_types=1);

function openai_builtin_pricing_catalog(): array
{
    return [
        'gpt-5.5' => [
            'input_per_1m' => 5.00,
            'cached_input_per_1m' => 0.50,
            'output_per_1m' => 30.00,
            'currency' => 'USD',
            'source' => 'builtin',
        ],
        'gpt-5.4' => [
            'input_per_1m' => 2.50,
            'cached_input_per_1m' => 0.25,
            'output_per_1m' => 15.00,
            'currency' => 'USD',
            'source' => 'builtin',
        ],
        'gpt-5.4-mini' => [
            'input_per_1m' => 0.75,
            'cached_input_per_1m' => 0.075,
            'output_per_1m' => 4.50,
            'currency' => 'USD',
            'source' => 'builtin',
        ],
        'gpt-5.4-nano' => [
            'input_per_1m' => 0.20,
            'cached_input_per_1m' => 0.02,
            'output_per_1m' => 1.25,
            'currency' => 'USD',
            'source' => 'builtin',
        ],
        'gpt-5.2' => [
            'input_per_1m' => 1.75,
            'cached_input_per_1m' => 0.175,
            'output_per_1m' => 14.00,
            'currency' => 'USD',
            'source' => 'builtin',
        ],
        'gpt-5-mini' => [
            'input_per_1m' => 0.25,
            'cached_input_per_1m' => 0.025,
            'output_per_1m' => 2.00,
            'currency' => 'USD',
            'source' => 'builtin',
        ],
        'yandexgpt-5.1' => [
            'input_per_1m' => 800.00,
            'cached_input_per_1m' => 800.00,
            'output_per_1m' => 800.00,
            'currency' => 'RUB',
            'source' => 'yandex_ai_studio_sync_2026_04_28',
        ],
        'yandexgpt-5.1/latest' => [
            'input_per_1m' => 800.00,
            'cached_input_per_1m' => 800.00,
            'output_per_1m' => 800.00,
            'currency' => 'RUB',
            'source' => 'yandex_ai_studio_sync_2026_04_28',
        ],
        'yandexgpt-5-pro' => [
            'input_per_1m' => 1200.00,
            'cached_input_per_1m' => 1200.00,
            'output_per_1m' => 1200.00,
            'currency' => 'RUB',
            'source' => 'yandex_ai_studio_sync_2026_04_28',
        ],
        'yandexgpt-5-pro/latest' => [
            'input_per_1m' => 1200.00,
            'cached_input_per_1m' => 1200.00,
            'output_per_1m' => 1200.00,
            'currency' => 'RUB',
            'source' => 'yandex_ai_studio_sync_2026_04_28',
        ],
        'yandexgpt-5-lite' => [
            'input_per_1m' => 200.00,
            'cached_input_per_1m' => 200.00,
            'output_per_1m' => 200.00,
            'currency' => 'RUB',
            'source' => 'yandex_ai_studio_sync_2026_04_28',
        ],
        'yandexgpt-5-lite/latest' => [
            'input_per_1m' => 200.00,
            'cached_input_per_1m' => 200.00,
            'output_per_1m' => 200.00,
            'currency' => 'RUB',
            'source' => 'yandex_ai_studio_sync_2026_04_28',
        ],
        'aliceai-llm' => [
            'input_per_1m' => 500.00,
            'cached_input_per_1m' => 500.00,
            'output_per_1m' => 1200.00,
            'currency' => 'RUB',
            'source' => 'yandex_ai_studio_sync_2026_04_28',
        ],
        'aliceai-llm/latest' => [
            'input_per_1m' => 500.00,
            'cached_input_per_1m' => 500.00,
            'output_per_1m' => 1200.00,
            'currency' => 'RUB',
            'source' => 'yandex_ai_studio_sync_2026_04_28',
        ],
        'gemma-3-27b-it' => [
            'input_per_1m' => 400.00,
            'cached_input_per_1m' => 400.00,
            'output_per_1m' => 400.00,
            'currency' => 'RUB',
            'source' => 'yandex_ai_studio_sync_2026_04_28',
        ],
        'gemini-3.1-pro-preview' => [
            'input_per_1m' => 2.00,
            'cached_input_per_1m' => 0.20,
            'output_per_1m' => 12.00,
            'input_per_1m_over_threshold' => 4.00,
            'cached_input_per_1m_over_threshold' => 0.40,
            'output_per_1m_over_threshold' => 18.00,
            'context_threshold_tokens' => 200000,
            'currency' => 'USD',
            'source' => 'google_gemini_api_standard_2026_04_28',
        ],
        'gemini-3.1-pro-preview-customtools' => [
            'input_per_1m' => 2.00,
            'cached_input_per_1m' => 0.20,
            'output_per_1m' => 12.00,
            'input_per_1m_over_threshold' => 4.00,
            'cached_input_per_1m_over_threshold' => 0.40,
            'output_per_1m_over_threshold' => 18.00,
            'context_threshold_tokens' => 200000,
            'currency' => 'USD',
            'source' => 'google_gemini_api_standard_2026_04_28',
        ],
        'gemini-3-flash-preview' => [
            'input_per_1m' => 0.50,
            'cached_input_per_1m' => 0.05,
            'output_per_1m' => 3.00,
            'currency' => 'USD',
            'source' => 'google_gemini_api_standard_2026_04_28',
        ],
        'gemini-3.1-flash-lite-preview' => [
            'input_per_1m' => 0.25,
            'cached_input_per_1m' => 0.025,
            'output_per_1m' => 1.50,
            'currency' => 'USD',
            'source' => 'google_gemini_api_standard_2026_04_28',
        ],
        'gemini-2.5-pro' => [
            'input_per_1m' => 1.25,
            'cached_input_per_1m' => 0.125,
            'output_per_1m' => 10.00,
            'input_per_1m_over_threshold' => 2.50,
            'cached_input_per_1m_over_threshold' => 0.25,
            'output_per_1m_over_threshold' => 15.00,
            'context_threshold_tokens' => 200000,
            'currency' => 'USD',
            'source' => 'google_gemini_api_standard_2026_04_28',
        ],
        'gemini-2.5-flash' => [
            'input_per_1m' => 0.30,
            'cached_input_per_1m' => 0.03,
            'output_per_1m' => 2.50,
            'currency' => 'USD',
            'source' => 'google_gemini_api_standard_2026_04_28',
        ],
        'gemini-2.5-flash-lite' => [
            'input_per_1m' => 0.10,
            'cached_input_per_1m' => 0.01,
            'output_per_1m' => 0.40,
            'currency' => 'USD',
            'source' => 'google_gemini_api_standard_2026_04_28',
        ],
        'gemini-2.5-flash-lite-preview-09-2025' => [
            'input_per_1m' => 0.10,
            'cached_input_per_1m' => 0.01,
            'output_per_1m' => 0.40,
            'currency' => 'USD',
            'source' => 'google_gemini_api_standard_2026_04_28',
        ],
    ];
}

function openai_builtin_image_pricing_catalog(): array
{
    return [
        'gpt-image-2' => [
            'text_input_per_1m' => 5.00,
            'text_cached_input_per_1m' => 1.25,
            'text_output_per_1m' => 0.00,
            'image_input_per_1m' => 8.00,
            'image_cached_input_per_1m' => 2.00,
            'image_output_per_1m' => 30.00,
            'currency' => 'USD',
            'source' => 'builtin_openai_2026_05_19',
        ],
        'gpt-image-1.5' => [
            'text_input_per_1m' => 5.00,
            'text_cached_input_per_1m' => 1.25,
            'text_output_per_1m' => 10.00,
            'image_input_per_1m' => 8.00,
            'image_cached_input_per_1m' => 2.00,
            'image_output_per_1m' => 32.00,
            'currency' => 'USD',
            'source' => 'builtin_openai_2026_05_19',
        ],
        'chatgpt-image-latest' => [
            'text_input_per_1m' => 5.00,
            'text_cached_input_per_1m' => 1.25,
            'text_output_per_1m' => 10.00,
            'image_input_per_1m' => 8.00,
            'image_cached_input_per_1m' => 2.00,
            'image_output_per_1m' => 32.00,
            'currency' => 'USD',
            'source' => 'builtin_openai_2026_05_19',
        ],
        'gpt-image-1' => [
            'text_input_per_1m' => 5.00,
            'text_cached_input_per_1m' => 1.25,
            'text_output_per_1m' => 0.00,
            'image_input_per_1m' => 10.00,
            'image_cached_input_per_1m' => 2.50,
            'image_output_per_1m' => 40.00,
            'currency' => 'USD',
            'source' => 'builtin_openai_2026_05_19',
        ],
        'gpt-image-1-mini' => [
            'text_input_per_1m' => 2.00,
            'text_cached_input_per_1m' => 0.20,
            'text_output_per_1m' => 0.00,
            'image_input_per_1m' => 2.50,
            'image_cached_input_per_1m' => 0.25,
            'image_output_per_1m' => 8.00,
            'currency' => 'USD',
            'source' => 'builtin_openai_2026_05_19',
        ],
    ];
}

function openai_builtin_video_pricing_catalog(): array
{
    return [
        'sora-2' => [
            'video_per_second' => 0.10,
            'video_1024p_per_second' => null,
            'video_1080p_per_second' => null,
            'currency' => 'USD',
            'source' => 'builtin_openai_2026_05_28',
        ],
        'sora-2-2025-12-08' => [
            'video_per_second' => 0.10,
            'video_1024p_per_second' => null,
            'video_1080p_per_second' => null,
            'currency' => 'USD',
            'source' => 'builtin_openai_2026_05_28',
        ],
        'sora-2-2025-10-06' => [
            'video_per_second' => 0.10,
            'video_1024p_per_second' => null,
            'video_1080p_per_second' => null,
            'currency' => 'USD',
            'source' => 'builtin_openai_2026_05_28',
        ],
        'sora-2-pro' => [
            'video_per_second' => 0.30,
            'video_1024p_per_second' => 0.50,
            'video_1080p_per_second' => 0.70,
            'currency' => 'USD',
            'source' => 'builtin_openai_2026_05_28',
        ],
        'sora-2-pro-2025-10-06' => [
            'video_per_second' => 0.30,
            'video_1024p_per_second' => 0.50,
            'video_1080p_per_second' => 0.70,
            'currency' => 'USD',
            'source' => 'builtin_openai_2026_05_28',
        ],
    ];
}

function openai_pricing_normalize_model_for_match(string $model): string
{
    $model = trim($model);
    if ($model === '') {
        return '';
    }

    if (str_starts_with(strtolower($model), 'gpt://')) {
        $path = parse_url($model, PHP_URL_PATH);
        if (is_string($path) && trim($path, '/') !== '') {
            return trim($path, '/');
        }
    }
    if (str_starts_with($model, 'models/')) {
        return substr($model, strlen('models/'));
    }

    return $model;
}

function openai_pricing_match_model_key(string $model, array $catalog): ?string
{
    $model = trim($model);
    if ($model === '') return null;

    $variants = array_values(array_unique(array_filter([
        $model,
        openai_pricing_normalize_model_for_match($model),
    ], static fn($v): bool => is_string($v) && trim($v) !== '')));

    foreach ($variants as $variant) {
        if (isset($catalog[$variant])) return $variant;
    }

    $keys = array_keys($catalog);
    usort($keys, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    foreach ($keys as $key) {
        foreach ($variants as $variant) {
            if (str_starts_with($variant, $key . '-') || str_starts_with($variant, $key . '/')) {
                return $key;
            }
            if (str_ends_with($variant, '/' . $key)) {
                return $key;
            }
        }
    }

    return null;
}

function openai_image_pricing_for_model(array $cfg, string $model): array
{
    $catalog = openai_builtin_image_pricing_catalog();

    $overrides = $cfg['openai_pricing']['image_models'] ?? null;
    if (is_array($overrides)) {
        foreach ($overrides as $key => $row) {
            if (!is_array($row)) continue;
            $catalog[(string)$key] = [
                'text_input_per_1m' => (float)($row['text_input_per_1m'] ?? 0.0),
                'text_cached_input_per_1m' => (float)($row['text_cached_input_per_1m'] ?? ($row['text_input_per_1m'] ?? 0.0)),
                'text_output_per_1m' => (float)($row['text_output_per_1m'] ?? 0.0),
                'image_input_per_1m' => (float)($row['image_input_per_1m'] ?? 0.0),
                'image_cached_input_per_1m' => (float)($row['image_cached_input_per_1m'] ?? ($row['image_input_per_1m'] ?? 0.0)),
                'image_output_per_1m' => (float)($row['image_output_per_1m'] ?? 0.0),
                'currency' => strtoupper(trim((string)($row['currency'] ?? 'USD'))) ?: 'USD',
                'source' => (string)($row['source'] ?? 'config'),
            ];
        }
    }

    $matched = openai_pricing_match_model_key($model, $catalog);
    if ($matched === null) {
        return [
            'requested_model' => $model,
            'matched_model' => $model,
            'text_input_per_1m' => 0.0,
            'text_cached_input_per_1m' => 0.0,
            'text_output_per_1m' => 0.0,
            'image_input_per_1m' => 0.0,
            'image_cached_input_per_1m' => 0.0,
            'image_output_per_1m' => 0.0,
            'currency' => 'USD',
            'source' => 'unknown',
        ];
    }

    $pricing = $catalog[$matched];
    $pricing['currency'] = strtoupper(trim((string)($pricing['currency'] ?? 'USD'))) ?: 'USD';
    $pricing['requested_model'] = $model;
    $pricing['matched_model'] = $matched;
    return $pricing;
}

function openai_video_pricing_for_model(array $cfg, string $model): array
{
    $catalog = openai_builtin_video_pricing_catalog();

    $overrides = $cfg['openai_pricing']['video_models'] ?? null;
    if (is_array($overrides)) {
        foreach ($overrides as $key => $row) {
            if (!is_array($row)) continue;
            $catalog[(string)$key] = [
                'video_per_second' => (float)($row['video_per_second'] ?? 0.0),
                'video_1024p_per_second' => isset($row['video_1024p_per_second']) ? (float)$row['video_1024p_per_second'] : null,
                'video_1080p_per_second' => isset($row['video_1080p_per_second']) ? (float)$row['video_1080p_per_second'] : null,
                'currency' => strtoupper(trim((string)($row['currency'] ?? 'USD'))) ?: 'USD',
                'source' => (string)($row['source'] ?? 'config'),
            ];
        }
    }

    $matched = openai_pricing_match_model_key($model, $catalog);
    if ($matched === null) {
        return [
            'requested_model' => $model,
            'matched_model' => $model,
            'video_per_second' => 0.0,
            'video_1024p_per_second' => null,
            'video_1080p_per_second' => null,
            'currency' => 'USD',
            'source' => 'unknown',
        ];
    }

    $pricing = $catalog[$matched];
    $pricing['currency'] = strtoupper(trim((string)($pricing['currency'] ?? 'USD'))) ?: 'USD';
    $pricing['requested_model'] = $model;
    $pricing['matched_model'] = $matched;
    return $pricing;
}

function openai_pricing_for_model(array $cfg, string $model): array
{
    $catalog = openai_builtin_pricing_catalog();

    $overrides = $cfg['openai_pricing']['models'] ?? null;
    if (is_array($overrides)) {
        foreach ($overrides as $key => $row) {
            if (!is_array($row)) continue;
            $catalog[(string)$key] = [
                'input_per_1m' => (float)($row['input_per_1m'] ?? 0.0),
                'cached_input_per_1m' => (float)($row['cached_input_per_1m'] ?? ($row['input_per_1m'] ?? 0.0)),
                'output_per_1m' => (float)($row['output_per_1m'] ?? 0.0),
                'input_per_1m_over_threshold' => isset($row['input_per_1m_over_threshold']) ? (float)$row['input_per_1m_over_threshold'] : null,
                'cached_input_per_1m_over_threshold' => isset($row['cached_input_per_1m_over_threshold']) ? (float)$row['cached_input_per_1m_over_threshold'] : null,
                'output_per_1m_over_threshold' => isset($row['output_per_1m_over_threshold']) ? (float)$row['output_per_1m_over_threshold'] : null,
                'context_threshold_tokens' => isset($row['context_threshold_tokens']) ? (int)$row['context_threshold_tokens'] : 0,
                'currency' => strtoupper(trim((string)($row['currency'] ?? 'USD'))) ?: 'USD',
                'source' => (string)($row['source'] ?? 'config'),
            ];
        }
    }

    $matched = openai_pricing_match_model_key($model, $catalog);
    if ($matched === null) {
        return [
            'requested_model' => $model,
            'matched_model' => $model,
            'input_per_1m' => 0.0,
            'cached_input_per_1m' => 0.0,
            'output_per_1m' => 0.0,
            'currency' => 'USD',
            'source' => 'unknown',
        ];
    }

    $pricing = $catalog[$matched];
    $pricing['currency'] = strtoupper(trim((string)($pricing['currency'] ?? 'USD'))) ?: 'USD';
    $pricing['requested_model'] = $model;
    $pricing['matched_model'] = $matched;
    return $pricing;
}

function openai_video_generation_cost_breakdown(array $cfg, string $model, int $seconds, string $size): array
{
    $pricing = openai_video_pricing_for_model($cfg, $model);
    $currency = strtoupper(trim((string)($pricing['currency'] ?? 'USD'))) ?: 'USD';
    $seconds = max(0, $seconds);
    $size = strtolower(trim($size));
    $is1024p = in_array($size, ['1024x1792', '1792x1024'], true);
    $is1080p = in_array($size, ['1080x1920', '1920x1080'], true);
    $rate = (float)($pricing['video_per_second'] ?? 0.0);
    $pricingMode = 'seconds';
    if ($is1080p && isset($pricing['video_1080p_per_second']) && $pricing['video_1080p_per_second'] !== null) {
        $rate = (float)$pricing['video_1080p_per_second'];
        $pricingMode = 'seconds_1080p';
    } elseif ($is1024p && isset($pricing['video_1024p_per_second']) && $pricing['video_1024p_per_second'] !== null) {
        $rate = (float)$pricing['video_1024p_per_second'];
        $pricingMode = 'seconds_1024p';
    }
    $cost = round(($seconds * $rate), 6);

    return [
        'pricing' => $pricing,
        'currency' => $currency,
        'currency_symbol' => openai_currency_symbol($currency),
        'video_rate_per_second' => $rate,
        'video_base_rate_per_second' => (float)($pricing['video_per_second'] ?? 0.0),
        'video_1024p_rate_per_second' => isset($pricing['video_1024p_per_second']) ? (float)$pricing['video_1024p_per_second'] : null,
        'video_1080p_rate_per_second' => isset($pricing['video_1080p_per_second']) ? (float)$pricing['video_1080p_per_second'] : null,
        'seconds' => $seconds,
        'size' => $size,
        'pricing_mode' => $pricingMode,
        'cost' => $cost,
        'cost_label' => openai_format_cost($cost, $currency),
        'cost_usd' => $cost,
    ];
}

function openai_currency_symbol(string $currency): string
{
    return match (strtoupper(trim($currency))) {
        'RUB' => '₽',
        'USD' => '$',
        'EUR' => '€',
        default => strtoupper(trim($currency)),
    };
}

function openai_format_cost(float $amount, string $currency): string
{
    $currency = strtoupper(trim($currency)) ?: 'USD';
    $precision = $currency === 'RUB' ? 4 : 6;
    $formatted = number_format($amount, $precision, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    if ($formatted === '') {
        $formatted = '0';
    }

    if ($currency === 'USD') {
        return '$' . $formatted;
    }
    if ($currency === 'RUB') {
        return $formatted . ' ₽';
    }
    return $formatted . ' ' . $currency;
}

function openai_format_cost_map(array $amountsByCurrency): string
{
    $parts = [];
    foreach ($amountsByCurrency as $currency => $amount) {
        $amount = (float)$amount;
        if (abs($amount) < 0.0000005) {
            continue;
        }
        $parts[] = openai_format_cost($amount, (string)$currency);
    }
    if (!$parts && count($amountsByCurrency) === 1) {
        return openai_format_cost(0.0, (string)array_key_first($amountsByCurrency));
    }
    return $parts ? implode(' + ', $parts) : openai_format_cost(0.0, 'USD');
}

function openai_cost_breakdown(array $cfg, string $model, int $inputTokens, int $cachedInputTokens, int $outputTokens): array
{
    $pricing = openai_pricing_for_model($cfg, $model);
    $currency = strtoupper(trim((string)($pricing['currency'] ?? 'USD'))) ?: 'USD';

    $inputTokens = max(0, $inputTokens);
    $cachedInputTokens = max(0, min($inputTokens, $cachedInputTokens));
    $outputTokens = max(0, $outputTokens);
    $billableInputTokens = max(0, $inputTokens - $cachedInputTokens);

    $inputRate = (float)$pricing['input_per_1m'];
    $cachedInputRate = (float)$pricing['cached_input_per_1m'];
    $outputRate = (float)$pricing['output_per_1m'];
    $threshold = max(0, (int)($pricing['context_threshold_tokens'] ?? 0));
    if ($threshold > 0 && $inputTokens > $threshold) {
        if (isset($pricing['input_per_1m_over_threshold']) && $pricing['input_per_1m_over_threshold'] !== null) {
            $inputRate = (float)$pricing['input_per_1m_over_threshold'];
        }
        if (isset($pricing['cached_input_per_1m_over_threshold']) && $pricing['cached_input_per_1m_over_threshold'] !== null) {
            $cachedInputRate = (float)$pricing['cached_input_per_1m_over_threshold'];
        }
        if (isset($pricing['output_per_1m_over_threshold']) && $pricing['output_per_1m_over_threshold'] !== null) {
            $outputRate = (float)$pricing['output_per_1m_over_threshold'];
        }
    }

    $inputCost = ($billableInputTokens / 1_000_000) * $inputRate;
    $cachedInputCost = ($cachedInputTokens / 1_000_000) * $cachedInputRate;
    $outputCost = ($outputTokens / 1_000_000) * $outputRate;
    $totalCost = $inputCost + $cachedInputCost + $outputCost;
    $cacheSavings = ($cachedInputTokens / 1_000_000) * max(0.0, $inputRate - $cachedInputRate);
    $inputCostRounded = round($inputCost, 6);
    $cachedInputCostRounded = round($cachedInputCost, 6);
    $outputCostRounded = round($outputCost, 6);
    $totalCostRounded = round($totalCost, 6);
    $cacheSavingsRounded = round($cacheSavings, 6);

    return [
        'pricing' => $pricing,
        'currency' => $currency,
        'currency_symbol' => openai_currency_symbol($currency),
        'input_rate_per_1m' => $inputRate,
        'cached_input_rate_per_1m' => $cachedInputRate,
        'output_rate_per_1m' => $outputRate,
        'input_tokens' => $inputTokens,
        'cached_input_tokens' => $cachedInputTokens,
        'billable_input_tokens' => $billableInputTokens,
        'output_tokens' => $outputTokens,
        'input_cost' => $inputCostRounded,
        'cached_input_cost' => $cachedInputCostRounded,
        'output_cost' => $outputCostRounded,
        'cost' => $totalCostRounded,
        'cache_savings' => $cacheSavingsRounded,
        'cost_label' => openai_format_cost($totalCostRounded, $currency),
        'cache_savings_label' => openai_format_cost($cacheSavingsRounded, $currency),
        // Legacy keys kept for old operation summaries. They now carry the
        // amount in the pricing currency; use `currency`/`cost_label` for UI.
        'input_cost_usd' => $inputCostRounded,
        'cached_input_cost_usd' => $cachedInputCostRounded,
        'output_cost_usd' => $outputCostRounded,
        'cost_usd' => $totalCostRounded,
        'cache_savings_usd' => $cacheSavingsRounded,
    ];
}

function openai_image_generation_cost_breakdown(
    array $cfg,
    string $model,
    int $textInputTokens,
    int $textCachedInputTokens,
    int $textOutputTokens,
    int $imageInputTokens,
    int $imageCachedInputTokens,
    int $imageOutputTokens
): array {
    $pricing = openai_image_pricing_for_model($cfg, $model);
    $currency = strtoupper(trim((string)($pricing['currency'] ?? 'USD'))) ?: 'USD';

    $textInputTokens = max(0, $textInputTokens);
    $textCachedInputTokens = max(0, min($textInputTokens, $textCachedInputTokens));
    $textOutputTokens = max(0, $textOutputTokens);
    $imageInputTokens = max(0, $imageInputTokens);
    $imageCachedInputTokens = max(0, min($imageInputTokens, $imageCachedInputTokens));
    $imageOutputTokens = max(0, $imageOutputTokens);

    $textBillableInputTokens = max(0, $textInputTokens - $textCachedInputTokens);
    $imageBillableInputTokens = max(0, $imageInputTokens - $imageCachedInputTokens);

    $textInputCost = ($textBillableInputTokens / 1_000_000) * (float)$pricing['text_input_per_1m'];
    $textCachedInputCost = ($textCachedInputTokens / 1_000_000) * (float)$pricing['text_cached_input_per_1m'];
    $textOutputCost = ($textOutputTokens / 1_000_000) * (float)$pricing['text_output_per_1m'];
    $imageInputCost = ($imageBillableInputTokens / 1_000_000) * (float)$pricing['image_input_per_1m'];
    $imageCachedInputCost = ($imageCachedInputTokens / 1_000_000) * (float)$pricing['image_cached_input_per_1m'];
    $imageOutputCost = ($imageOutputTokens / 1_000_000) * (float)$pricing['image_output_per_1m'];
    $totalCost = $textInputCost + $textCachedInputCost + $textOutputCost + $imageInputCost + $imageCachedInputCost + $imageOutputCost;

    $costRounded = round($totalCost, 6);
    return [
        'pricing' => $pricing,
        'currency' => $currency,
        'currency_symbol' => openai_currency_symbol($currency),
        'text_input_rate_per_1m' => (float)$pricing['text_input_per_1m'],
        'text_cached_input_rate_per_1m' => (float)$pricing['text_cached_input_per_1m'],
        'text_output_rate_per_1m' => (float)$pricing['text_output_per_1m'],
        'image_input_rate_per_1m' => (float)$pricing['image_input_per_1m'],
        'image_cached_input_rate_per_1m' => (float)$pricing['image_cached_input_per_1m'],
        'image_output_rate_per_1m' => (float)$pricing['image_output_per_1m'],
        'text_input_tokens' => $textInputTokens,
        'text_cached_input_tokens' => $textCachedInputTokens,
        'text_billable_input_tokens' => $textBillableInputTokens,
        'text_output_tokens' => $textOutputTokens,
        'image_input_tokens' => $imageInputTokens,
        'image_cached_input_tokens' => $imageCachedInputTokens,
        'image_billable_input_tokens' => $imageBillableInputTokens,
        'image_output_tokens' => $imageOutputTokens,
        'text_input_cost' => round($textInputCost, 6),
        'text_cached_input_cost' => round($textCachedInputCost, 6),
        'text_output_cost' => round($textOutputCost, 6),
        'image_input_cost' => round($imageInputCost, 6),
        'image_cached_input_cost' => round($imageCachedInputCost, 6),
        'image_output_cost' => round($imageOutputCost, 6),
        'cost' => $costRounded,
        'cost_label' => openai_format_cost($costRounded, $currency),
        // Legacy naming for callers that still expect USD fields.
        'cost_usd' => $costRounded,
    ];
}

function openai_cost_usd(array $cfg, string $model, int $inputTokens, int $cachedInputTokens, int $outputTokens): float
{
    return (float)openai_cost_breakdown($cfg, $model, $inputTokens, $cachedInputTokens, $outputTokens)['cost'];
}

function openai_cost_currency_for_model(array $cfg, string $model): string
{
    $pricing = openai_pricing_for_model($cfg, $model);
    return strtoupper(trim((string)($pricing['currency'] ?? 'USD'))) ?: 'USD';
}

function openai_pricing_debug_string(array $pricing): string
{
    $currency = strtoupper(trim((string)($pricing['currency'] ?? 'USD'))) ?: 'USD';
    return sprintf(
        'requested=%s matched=%s input=%.3f %s/1M cached=%.3f %s/1M output=%.3f %s/1M source=%s',
        (string)($pricing['requested_model'] ?? ''),
        (string)($pricing['matched_model'] ?? ''),
        (float)($pricing['input_per_1m'] ?? 0.0),
        $currency,
        (float)($pricing['cached_input_per_1m'] ?? 0.0),
        $currency,
        (float)($pricing['output_per_1m'] ?? 0.0),
        $currency,
        (string)($pricing['source'] ?? 'unknown')
    );
}

function openai_image_pricing_debug_string(array $pricing): string
{
    $currency = strtoupper(trim((string)($pricing['currency'] ?? 'USD'))) ?: 'USD';
    return sprintf(
        'requested=%s matched=%s text_input=%.3f %s/1M text_cached=%.3f %s/1M text_output=%.3f %s/1M image_input=%.3f %s/1M image_cached=%.3f %s/1M image_output=%.3f %s/1M source=%s',
        (string)($pricing['requested_model'] ?? ''),
        (string)($pricing['matched_model'] ?? ''),
        (float)($pricing['text_input_per_1m'] ?? 0.0),
        $currency,
        (float)($pricing['text_cached_input_per_1m'] ?? 0.0),
        $currency,
        (float)($pricing['text_output_per_1m'] ?? 0.0),
        $currency,
        (float)($pricing['image_input_per_1m'] ?? 0.0),
        $currency,
        (float)($pricing['image_cached_input_per_1m'] ?? 0.0),
        $currency,
        (float)($pricing['image_output_per_1m'] ?? 0.0),
        $currency,
        (string)($pricing['source'] ?? 'unknown')
    );
}

function openai_video_pricing_debug_string(array $pricing): string
{
    $currency = strtoupper(trim((string)($pricing['currency'] ?? 'USD'))) ?: 'USD';
    $rate1080 = $pricing['video_1080p_per_second'] ?? null;
    $rate1024 = $pricing['video_1024p_per_second'] ?? null;
    return sprintf(
        'requested=%s matched=%s video=%.3f %s/sec 1024p=%s 1080p=%s source=%s',
        (string)($pricing['requested_model'] ?? ''),
        (string)($pricing['matched_model'] ?? ''),
        (float)($pricing['video_per_second'] ?? 0.0),
        $currency,
        $rate1024 === null ? '-' : sprintf('%.3f %s/sec', (float)$rate1024, $currency),
        $rate1080 === null ? '-' : sprintf('%.3f %s/sec', (float)$rate1080, $currency),
        (string)($pricing['source'] ?? 'unknown')
    );
}

function openai_model_supports_extended_prompt_cache(string $model): bool
{
    $supported = [
        'gpt-5.4',
        'gpt-5.2',
        'gpt-5.1',
        'gpt-5.1-codex',
        'gpt-5.1-codex-mini',
        'gpt-5.1-codex-max',
        'gpt-5.1-chat-latest',
        'gpt-5',
        'gpt-5-codex',
        'gpt-4.1',
    ];
    return openai_pricing_match_model_key($model, array_fill_keys($supported, true)) !== null;
}
