<?php

require_once __DIR__ . '/OpenAIClient.php';

final class LLM
{
    public static function config(array $config): array
    {
        $llm = $config['llm'] ?? null;
        if (!is_array($llm)) {
            $llm = [];
        }
        $openai = $config['openai'] ?? null;
        if (!is_array($openai)) {
            $openai = [];
        }
        return array_replace_recursive($openai, $llm);
    }

    public static function client(array $config, ?string $model = null): OpenAIClient
    {
        return new OpenAIClient($model !== null && trim($model) !== ''
            ? self::configForModel($config, $model)
            : self::config($config));
    }

    public static function configForModel(array $config, string $model): array
    {
        $base = self::config($config);
        $provider = self::providerForModel($config, $model);
        if ($provider === '') {
            return $base;
        }

        $providers = self::providerConfigs($config);
        if (!isset($providers[$provider]) || !is_array($providers[$provider])) {
            return $base;
        }

        return array_replace_recursive($base, $providers[$provider]);
    }

    public static function providerForModel(array $config, string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            $llm = self::config($config);
            return strtolower(trim((string)($llm['provider'] ?? '')));
        }

        $providers = self::providerConfigs($config);
        foreach ($providers as $name => $providerConfig) {
            if (!is_array($providerConfig)) {
                continue;
            }
            $models = $providerConfig['models'] ?? [];
            if (is_string($models)) {
                $models = array_map('trim', explode(',', $models));
            }
            if (!is_array($models)) {
                continue;
            }
            foreach ($models as $candidate) {
                if (trim((string)$candidate) === $model) {
                    return strtolower(trim((string)$name));
                }
            }
        }

        $norm = strtolower($model);
        if (str_starts_with($norm, 'gpt://')) {
            return 'yandex';
        }
        if (str_starts_with($norm, 'yandexgpt-')
            || str_starts_with($norm, 'aliceai-')
            || str_starts_with($norm, 'gemma-')) {
            return 'yandex';
        }
        if (str_starts_with($norm, 'gemini-')) {
            return 'gemini';
        }
        if (str_starts_with($norm, 'gpt-')
            || str_starts_with($norm, 'chatgpt-')
            || preg_match('/^o\\d/', $norm)
            || str_starts_with($norm, 'o-')) {
            return 'openai';
        }

        $llm = self::config($config);
        return strtolower(trim((string)($llm['provider'] ?? '')));
    }

    public static function providerConfigs(array $config): array
    {
        $providers = $config['llm_providers'] ?? [];
        return is_array($providers) ? $providers : [];
    }

    public static function modelForOp(array $config, array $opParams): string
    {
        $m = trim((string)($opParams['model'] ?? ''));
        if ($m !== '') return self::resolveModelAlias($config, $m);

        $llm = self::config($config);
        return self::resolveModelAlias($config, (string)($llm['default_model'] ?? 'gpt-5-mini'));
    }

    public static function resolveModelAlias(array $config, string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            return $model;
        }

        $aliases = [];
        $llm = self::config($config);
        foreach (self::parseModelAliases($llm['model_aliases'] ?? ($llm['model_aliases_json'] ?? '')) as $from => $to) {
            $aliases[$from] = $to;
        }
        $provider = self::providerForModel($config, $model);
        if ($provider !== '') {
            $providers = self::providerConfigs($config);
            $providerConfig = is_array($providers[$provider] ?? null) ? $providers[$provider] : [];
            foreach (self::parseModelAliases($providerConfig['model_aliases'] ?? ($providerConfig['model_aliases_json'] ?? '')) as $from => $to) {
                $aliases[$from] = $to;
            }
        }

        $seen = [];
        for ($i = 0; $i < 5; $i++) {
            if (isset($seen[$model]) || !isset($aliases[$model])) {
                break;
            }
            $seen[$model] = true;
            $next = trim((string)$aliases[$model]);
            if ($next === '') {
                break;
            }
            $model = $next;
        }
        return $model;
    }

    private static function parseModelAliases(mixed $aliases): array
    {
        if (is_string($aliases)) {
            $json = trim($aliases);
            $aliases = $json !== '' ? json_decode($json, true) : [];
        }
        if (!is_array($aliases)) {
            return [];
        }

        $out = [];
        foreach ($aliases as $from => $to) {
            $from = trim((string)$from);
            $to = trim((string)$to);
            if ($from !== '' && $to !== '') {
                $out[$from] = $to;
            }
        }
        return $out;
    }

    public static function modelForVisionOp(array $config, array $opParams): string
    {
        $selected = self::modelForOp($config, $opParams);
        $provider = self::providerForModel($config, $selected);

        if (($provider === 'yandex' && !self::looksLikeYandexVisionModel($selected)) || $provider === 'gemini') {
            $llm = self::configForModel($config, $selected);
            $visionModel = trim((string)($opParams['vision_model'] ?? ($llm['vision_model'] ?? '')));
            if ($visionModel !== '') {
                return $visionModel;
            }
        }

        return $selected;
    }

    private static function looksLikeYandexVisionModel(string $model): bool
    {
        $model = strtolower(trim($model));
        return str_contains($model, 'gemma-3')
            || str_contains($model, 'vl')
            || str_contains($model, 'vision');
    }

    public static function modelOptions(array $config): array
    {
        $llm = self::config($config);
        $models = $llm['models'] ?? [];
        if (is_string($models)) {
            $models = array_map('trim', explode(',', $models));
        }
        if (!is_array($models)) {
            $models = [];
        }
        $default = trim((string)($llm['default_model'] ?? ''));
        $out = [];
        foreach ($models as $model) {
            $model = trim((string)$model);
            if ($model !== '') {
                $out[$model] = $model;
            }
        }
        foreach (self::providerConfigs($config) as $providerConfig) {
            if (!is_array($providerConfig)) {
                continue;
            }
            $providerModels = $providerConfig['models'] ?? [];
            if (is_string($providerModels)) {
                $providerModels = array_map('trim', explode(',', $providerModels));
            }
            if (!is_array($providerModels)) {
                continue;
            }
            foreach ($providerModels as $model) {
                $model = trim((string)$model);
                if ($model !== '') {
                    $out[$model] = $model;
                }
            }
        }
        if ($default !== '') {
            $out = [$default => $default] + $out;
        }
        return array_values($out);
    }
}
