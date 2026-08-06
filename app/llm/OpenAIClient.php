<?php

require_once __DIR__ . '/OpenAIRequestLog.php';
require_once __DIR__ . '/OpenAIPricing.php';

final class OpenAIClient
{
    private string $provider;
    private string $apiFormat;
    private string $apiKey;
    private string $baseUrl;
    private string $modelPrefix;
    private array $modelAliases;
    private int $remoteImageMaxBytes;
    private int $remoteImageTimeoutSec;
    private int $timeoutSec;
    private int $retries;
    private int $retryBaseDelayMs;
    private string $authType;
    private string $authHeader;
    private string $authValuePrefix;
    private array $extraHeaders;
    private string $ipResolve;
    private bool $tlsVerify;
    private string $oauthUrl;
    private string $oauthScope;
    private string $oauthCacheFile;
    private bool $responseCacheEnabled;
    private string $responseCacheDir;
    private int $responseCacheTtlSec;
    private bool $promptCacheKeyEnabled;
    private string $promptCacheRetention;
    private bool $requestLogEnabled;
    private int $requestLogMaxRequestChars;
    private int $requestLogMaxResponseChars;

    public function __construct(array $openaiConfig)
    {
        $this->provider = trim((string)($openaiConfig['provider'] ?? 'openai'));
        $this->apiFormat = $this->normalizeApiFormat((string)($openaiConfig['api_format'] ?? 'responses'));
        $this->apiKey = (string)($openaiConfig['api_key'] ?? '');
        $this->baseUrl = rtrim((string)($openaiConfig['base_url'] ?? 'https://api.openai.com/v1'), '/');
        $this->modelPrefix = trim((string)($openaiConfig['model_prefix'] ?? ''));
        $this->modelAliases = $this->parseModelAliases($openaiConfig['model_aliases'] ?? ($openaiConfig['model_aliases_json'] ?? ''));
        $this->remoteImageMaxBytes = max(1, (int)($openaiConfig['remote_image_max_bytes'] ?? (10 * 1024 * 1024)));
        $this->remoteImageTimeoutSec = max(1, (int)($openaiConfig['remote_image_timeout_sec'] ?? 20));
        $this->timeoutSec = (int)($openaiConfig['timeout_sec'] ?? 60);
        $this->retries = (int)($openaiConfig['retries'] ?? 4);
        $this->retryBaseDelayMs = (int)($openaiConfig['retry_base_delay_ms'] ?? 250);
        $this->authType = trim((string)($openaiConfig['auth_type'] ?? 'bearer'));
        $this->authHeader = trim((string)($openaiConfig['auth_header'] ?? 'Authorization')) ?: 'Authorization';
        $this->authValuePrefix = trim((string)($openaiConfig['auth_value_prefix'] ?? ''));
        $this->extraHeaders = $this->parseExtraHeaders((string)($openaiConfig['extra_headers_json'] ?? ''));
        $this->ipResolve = $this->normalizeIpResolve((string)($openaiConfig['ip_resolve'] ?? ''));
        $this->tlsVerify = array_key_exists('tls_verify', $openaiConfig) ? !empty($openaiConfig['tls_verify']) : true;
        $this->oauthUrl = trim((string)($openaiConfig['oauth_url'] ?? 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth'));
        $this->oauthScope = trim((string)($openaiConfig['oauth_scope'] ?? 'GIGACHAT_API_PERS')) ?: 'GIGACHAT_API_PERS';
        $this->oauthCacheFile = trim((string)($openaiConfig['oauth_cache_file'] ?? (__DIR__ . '/../../storage/cache/llm_oauth_token.json')));
        $this->responseCacheEnabled = !empty($openaiConfig['response_cache_enabled']);
        $this->responseCacheDir = rtrim((string)($openaiConfig['response_cache_dir'] ?? (__DIR__ . '/../../storage/cache/openai_responses')), '/\\');
        $this->responseCacheTtlSec = (int)($openaiConfig['response_cache_ttl_sec'] ?? (30 * 24 * 60 * 60));
        $this->promptCacheKeyEnabled = !empty($openaiConfig['prompt_cache_key_enabled']);
        $this->promptCacheRetention = trim((string)($openaiConfig['prompt_cache_retention'] ?? ''));
        $this->requestLogEnabled = !empty($openaiConfig['request_log_enabled']);
        $this->requestLogMaxRequestChars = (int)($openaiConfig['request_log_max_request_chars'] ?? 200000);
        $this->requestLogMaxResponseChars = (int)($openaiConfig['request_log_max_response_chars'] ?? 200000);

        if ($this->apiKey === '') {
            throw new RuntimeException('LLM API key is not set');
        }
    }

    private function normalizeApiFormat(string $format): string
    {
        $format = strtolower(trim($format));
        if (in_array($format, ['chat', 'chat_completion', 'chat_completions', 'openai_chat'], true)) {
            return 'chat_completions';
        }
        if (in_array($format, ['gemini', 'gemini_native', 'google_gemini', 'generate_content'], true)) {
            return 'gemini_native';
        }
        return 'responses';
    }

    private function parseExtraHeaders(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $name => $value) {
            $name = trim((string)$name);
            if ($name !== '') {
                $out[$name] = (string)$value;
            }
        }
        return $out;
    }

    private function normalizeIpResolve(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            '4', 'v4', 'ipv4' => 'v4',
            '6', 'v6', 'ipv6' => 'v6',
            default => '',
        };
    }

    private function applyIpResolveOption($ch): void
    {
        if ($this->ipResolve === 'v4') {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            return;
        }
        if ($this->ipResolve === 'v6') {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V6);
        }
    }

    private function parseModelAliases(mixed $aliases): array
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

    private function resolveModelName(string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            return $model;
        }
        if (isset($this->modelAliases[$model])) {
            return $this->modelAliases[$model];
        }
        if ($this->modelPrefix !== '' && !str_contains($model, '://')) {
            return rtrim($this->modelPrefix, '/') . '/' . ltrim($model, '/');
        }
        return $model;
    }

    private function modelDisallowsTemperature(string $model): bool
    {
        $model = strtolower(trim(openai_pricing_normalize_model_for_match($model)));
        return (bool)preg_match('/^gpt-5\.5(?:$|[-\/])/', $model);
    }

    private function stripUnsupportedOptionsForModel(string $model, array $options): array
    {
        if ($this->modelDisallowsTemperature($model)) {
            unset($options['temperature']);
        }
        return $options;
    }

    /**
     * Responses API: https://api.openai.com/v1/responses
     * - input: string|array
     * - instructions: string|null
     */
    public function createResponse(array $payload): array
    {
        $noLocalCache = !empty($payload['_feedtools_no_response_cache']);
        unset($payload['_feedtools_no_response_cache']);

        $payload = $this->applyPromptCachingOptions($payload);
        $cacheKey = $this->responseCacheKey('POST', '/responses', $payload);
        $startedAt = hrtime(true);

        if (!$noLocalCache && $this->responseCacheEnabled) {
            $cached = $this->readResponseCache($cacheKey);
            if (is_array($cached)) {
                $marked = $this->markLocalCacheHit($cached, $cacheKey);
                $this->logOpenAIRequest($payload, $this->sanitizeResponsesApiResponseForLog($marked), null, $cacheKey, true, $startedAt, '/responses');
                return $marked;
            }
        }

        try {
            $res = $this->requestWithRetries('POST', '/responses', $payload);
        } catch (Throwable $e) {
            $this->logOpenAIRequest($payload, null, $e, $cacheKey, false, $startedAt, '/responses');
            throw $e;
        }

        $this->logOpenAIRequest($payload, $this->sanitizeResponsesApiResponseForLog($res), null, $cacheKey, false, $startedAt, '/responses');

        if (!$noLocalCache && $this->responseCacheEnabled) {
            $this->writeResponseCache($cacheKey, $res);
        }

        return $res;
    }

    public function generateImageWithResponsesTool(
        string $responseModel,
        string $imageModel,
        string $prompt,
        array $options = []
    ): array {
        return $this->runImageGenerationTool($responseModel, $imageModel, $prompt, null, '', $options, 'generate');
    }

    public function editImageWithResponsesTool(
        string $responseModel,
        string $imageModel,
        string $prompt,
        string $imageBytes,
        string $mimeType = 'image/png',
        array $options = []
    ): array {
        if ($imageBytes === '') {
            throw new RuntimeException('Responses image_generation edit requires a non-empty image.');
        }
        $mimeType = strtolower(trim($mimeType)) ?: 'image/png';
        if (!str_starts_with($mimeType, 'image/')) {
            $mimeType = 'image/png';
        }

        return $this->runImageGenerationTool($responseModel, $imageModel, $prompt, $imageBytes, $mimeType, $options, 'edit');
    }

    private function runImageGenerationTool(
        string $responseModel,
        string $imageModel,
        string $prompt,
        ?string $imageBytes,
        string $mimeType,
        array $options,
        string $action
    ): array {
        $resolvedResponseModel = $this->resolveModelName($responseModel);
        $resolvedImageModel = $this->resolveModelName($imageModel);
        if (trim($resolvedResponseModel) === '') {
            throw new RuntimeException('Responses image_generation requires a response model.');
        }
        if (trim($resolvedImageModel) === '') {
            throw new RuntimeException('Responses image_generation requires an image model.');
        }

        $content = [
            ['type' => 'input_text', 'text' => $prompt],
        ];
        if ($imageBytes !== null) {
            $imagePart = [
                'type' => 'input_image',
                'image_url' => 'data:' . $mimeType . ';base64,' . base64_encode($imageBytes),
            ];
            $detail = trim((string)($options['detail'] ?? ''));
            if ($detail !== '') {
                $imagePart['detail'] = $detail;
            }
            $content[] = $imagePart;
        }

        $tool = [
            'type' => 'image_generation',
            'model' => $resolvedImageModel,
            'action' => in_array($action, ['generate', 'edit', 'auto'], true) ? $action : 'auto',
        ];
        foreach (['size', 'quality', 'output_format', 'background', 'moderation', 'input_fidelity'] as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            $value = trim((string)$options[$key]);
            if ($value !== '') {
                $tool[$key] = $value;
            }
        }
        if (isset($options['output_compression'])) {
            $tool['output_compression'] = max(0, min(100, (int)$options['output_compression']));
        }
        if (isset($options['partial_images'])) {
            $tool['partial_images'] = max(0, min(3, (int)$options['partial_images']));
        }

        $payload = [
            'model' => $resolvedResponseModel,
            'input' => [[
                'role' => 'user',
                'content' => $content,
            ]],
            'tools' => [$tool],
            'store' => false,
            '_feedtools_no_response_cache' => true,
        ];
        if (isset($options['prompt_cache_key']) && trim((string)$options['prompt_cache_key']) !== '') {
            $payload['prompt_cache_key'] = (string)$options['prompt_cache_key'];
        }
        if (isset($options['prompt_cache_retention']) && trim((string)$options['prompt_cache_retention']) !== '') {
            $payload['prompt_cache_retention'] = (string)$options['prompt_cache_retention'];
        }

        $raw = $this->createResponse($payload);
        $imageCall = null;
        foreach ((array)($raw['output'] ?? []) as $item) {
            if (!is_array($item) || (string)($item['type'] ?? '') !== 'image_generation_call') {
                continue;
            }
            if (trim((string)($item['result'] ?? '')) !== '') {
                $imageCall = $item;
                break;
            }
        }
        if (!is_array($imageCall)) {
            $status = trim((string)($raw['status'] ?? ''));
            $error = $raw['error'] ?? null;
            $message = is_array($error) ? trim((string)($error['message'] ?? '')) : '';
            throw new RuntimeException(
                'Responses image_generation не вернул изображение' .
                ($status !== '' ? ' (status=' . $status . ')' : '') .
                ($message !== '' ? ': ' . $message : '.')
            );
        }

        $b64 = trim((string)($imageCall['result'] ?? ''));
        return [
            'created' => (int)($raw['created_at'] ?? time()),
            'data' => [[
                'b64_json' => $b64,
                'revised_prompt' => (string)($imageCall['revised_prompt'] ?? ''),
            ]],
            'background' => (string)($tool['background'] ?? ''),
            'output_format' => (string)($tool['output_format'] ?? 'png'),
            'quality' => (string)($tool['quality'] ?? ''),
            'size' => (string)($tool['size'] ?? ''),
            'usage' => is_array($raw['usage'] ?? null) ? $raw['usage'] : [],
            '_responses_api' => true,
            '_responses_model' => $resolvedResponseModel,
            '_responses_image_model' => $resolvedImageModel,
            '_responses_image_call' => $this->sanitizeResponsesImageCallForLog($imageCall),
        ];
    }

    public function editImage(
        string $model,
        string $prompt,
        string $imageBytes,
        string $mimeType = 'image/png',
        array $options = []
    ): array {
        $resolvedModel = $this->resolveModelName($model);
        $mimeType = strtolower(trim($mimeType)) ?: 'image/png';
        if (!str_starts_with($mimeType, 'image/')) {
            $mimeType = 'image/png';
        }
        if ($imageBytes === '') {
            throw new RuntimeException('Image API edit requires a non-empty image.');
        }

        $fields = [
            'model' => $resolvedModel,
            'prompt' => $prompt,
        ];
        foreach (['size', 'quality', 'output_format', 'background', 'moderation', 'input_fidelity'] as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            $value = trim((string)$options[$key]);
            if ($value !== '') {
                $fields[$key] = $value;
            }
        }
        if (isset($options['output_compression'])) {
            $fields['output_compression'] = (string)max(0, min(100, (int)$options['output_compression']));
        }
        $fields = $this->applyImagePromptCachingOptions($fields, $options);

        $tmp = $this->writeTempImageFile($imageBytes, $mimeType);
        $cacheKey = $this->responseCacheKey('POST', '/images/edits', [
            'model' => $resolvedModel,
            'prompt' => $prompt,
            'options' => $fields,
            'image_sha256' => hash('sha256', $imageBytes),
            'image_bytes' => strlen($imageBytes),
            'image_mime' => $mimeType,
        ]);
        $startedAt = hrtime(true);
        $logPayload = $fields + [
            'image' => [
                'mime' => $mimeType,
                'bytes' => strlen($imageBytes),
                'sha256' => hash('sha256', $imageBytes),
            ],
        ];

        try {
            $fields['image[]'] = new CURLFile($tmp, $mimeType, 'input.' . $this->extensionForMime($mimeType));
            $res = $this->requestMultipartWithRetries('POST', '/images/edits', $fields);
        } catch (Throwable $e) {
            if ($this->hasImagePromptCachingOptions($fields) && $this->isUnsupportedPromptCachingOptionError($e)) {
                try {
                    $retryFields = $this->stripImagePromptCachingOptions($fields);
                    $retryFields['image[]'] = new CURLFile($tmp, $mimeType, 'input.' . $this->extensionForMime($mimeType));
                    $res = $this->requestMultipartWithRetries('POST', '/images/edits', $retryFields);
                    @unlink($tmp);
                    $retryLogPayload = $this->stripImagePromptCachingOptions($logPayload);
                    $retryLogPayload['_feedtools_prompt_cache_retry_without_key'] = true;
                    $retryLogPayload['_feedtools_prompt_cache_error'] = $this->redactSecretsForLog($e->getMessage());
                    $this->logOpenAIRequest(
                        $retryLogPayload,
                        $this->sanitizeImageApiResponseForLog($res),
                        null,
                        $cacheKey,
                        false,
                        $startedAt,
                        '/images/edits'
                    );
                    return $res;
                } catch (Throwable $retryError) {
                    @unlink($tmp);
                    $this->logOpenAIRequest($logPayload, null, $retryError, $cacheKey, false, $startedAt, '/images/edits');
                    throw $retryError;
                }
            }
            @unlink($tmp);
            $this->logOpenAIRequest($logPayload, null, $e, $cacheKey, false, $startedAt, '/images/edits');
            throw $e;
        }
        @unlink($tmp);

        $this->logOpenAIRequest(
            $logPayload,
            $this->sanitizeImageApiResponseForLog($res),
            null,
            $cacheKey,
            false,
            $startedAt,
            '/images/edits'
        );

        return $res;
    }

    public function generateImage(
        string $model,
        string $prompt,
        array $options = []
    ): array {
        $resolvedModel = $this->resolveModelName($model);
        $fields = [
            'model' => $resolvedModel,
            'prompt' => $prompt,
        ];
        foreach (['size', 'quality', 'output_format', 'background', 'moderation', 'n'] as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            $value = $options[$key];
            if ($key === 'n') {
                $value = max(1, min(10, (int)$value));
            } else {
                $value = trim((string)$value);
            }
            if ($value !== '') {
                $fields[$key] = $value;
            }
        }
        if (isset($options['output_compression'])) {
            $fields['output_compression'] = (string)max(0, min(100, (int)$options['output_compression']));
        }
        $fields = $this->applyImagePromptCachingOptions($fields, $options);

        $cacheKey = $this->responseCacheKey('POST', '/images/generations', $fields);
        $startedAt = hrtime(true);

        try {
            $res = $this->requestWithRetries('POST', '/images/generations', $fields);
        } catch (Throwable $e) {
            if ($this->hasImagePromptCachingOptions($fields) && $this->isUnsupportedPromptCachingOptionError($e)) {
                try {
                    $retryFields = $this->stripImagePromptCachingOptions($fields);
                    $res = $this->requestWithRetries('POST', '/images/generations', $retryFields);
                    $retryLogPayload = $retryFields;
                    $retryLogPayload['_feedtools_prompt_cache_retry_without_key'] = true;
                    $retryLogPayload['_feedtools_prompt_cache_error'] = $this->redactSecretsForLog($e->getMessage());
                    $this->logOpenAIRequest(
                        $retryLogPayload,
                        $this->sanitizeImageApiResponseForLog($res),
                        null,
                        $cacheKey,
                        false,
                        $startedAt,
                        '/images/generations'
                    );
                    return $res;
                } catch (Throwable $retryError) {
                    $this->logOpenAIRequest($fields, null, $retryError, $cacheKey, false, $startedAt, '/images/generations');
                    throw $retryError;
                }
            }
            $this->logOpenAIRequest($fields, null, $e, $cacheKey, false, $startedAt, '/images/generations');
            throw $e;
        }

        $this->logOpenAIRequest(
            $fields,
            $this->sanitizeImageApiResponseForLog($res),
            null,
            $cacheKey,
            false,
            $startedAt,
            '/images/generations'
        );

        return $res;
    }

    public function createVideo(
        string $model,
        string $prompt,
        array $options = [],
        ?string $inputReferenceBytes = null,
        string $mimeType = 'image/jpeg'
    ): array {
        $resolvedModel = $this->resolveModelName($model);
        if (trim($resolvedModel) === '') {
            throw new RuntimeException('Videos API requires a model.');
        }
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('Videos API requires a prompt.');
        }
        $mimeType = strtolower(trim($mimeType)) ?: 'image/jpeg';
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $mimeType = 'image/jpeg';
        }

        $fields = [
            'model' => $resolvedModel,
            'prompt' => $prompt,
        ];
        foreach (['size', 'seconds', 'quality'] as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            $value = trim((string)$options[$key]);
            if ($value !== '') {
                $fields[$key] = $value;
            }
        }

        $inputBytes = is_string($inputReferenceBytes) ? $inputReferenceBytes : '';
        $tmp = '';
        $logPayload = $fields;
        if ($inputBytes !== '') {
            $tmp = $this->writeTempImageFile($inputBytes, $mimeType);
            $logPayload['input_reference'] = [
                'mime' => $mimeType,
                'bytes' => strlen($inputBytes),
                'sha256' => hash('sha256', $inputBytes),
            ];
            $fields['input_reference'] = new CURLFile($tmp, $mimeType, 'input_reference.' . $this->extensionForMime($mimeType));
        }

        $cacheKey = $this->responseCacheKey('POST', '/videos', $logPayload);
        $startedAt = hrtime(true);
        try {
            $res = $this->requestMultipartWithRetries('POST', '/videos', $fields);
        } catch (Throwable $e) {
            if ($tmp !== '') {
                @unlink($tmp);
            }
            $this->logOpenAIRequest($logPayload, null, $e, $cacheKey, false, $startedAt, '/videos');
            throw $e;
        }
        if ($tmp !== '') {
            @unlink($tmp);
        }

        $this->logOpenAIRequest($logPayload, $this->sanitizeVideoApiResponseForLog($res), null, $cacheKey, false, $startedAt, '/videos');
        return $res;
    }

    public function retrieveVideo(string $videoId): array
    {
        $videoId = trim($videoId);
        if ($videoId === '') {
            throw new RuntimeException('Videos API retrieve requires video id.');
        }
        $path = '/videos/' . rawurlencode($videoId);
        $payload = ['video_id' => $videoId];
        $cacheKey = $this->responseCacheKey('GET', $path, $payload);
        $startedAt = hrtime(true);
        try {
            $res = $this->requestNoBodyWithRetries('GET', $path);
        } catch (Throwable $e) {
            $this->logOpenAIRequest($payload, null, $e, $cacheKey, false, $startedAt, $path);
            throw $e;
        }
        $this->logOpenAIRequest($payload, $this->sanitizeVideoApiResponseForLog($res), null, $cacheKey, false, $startedAt, $path);
        return $res;
    }

    public function downloadVideoContent(string $videoId, string $variant = 'video'): string
    {
        $videoId = trim($videoId);
        if ($videoId === '') {
            throw new RuntimeException('Videos API download requires video id.');
        }
        $variant = trim($variant);
        $path = '/videos/' . rawurlencode($videoId) . '/content';
        if ($variant !== '' && $variant !== 'video') {
            $path .= '?variant=' . rawurlencode($variant);
        }
        return $this->requestBinaryNoBodyWithRetries('GET', $path);
    }

    public function generateText(
        string $model,
        string|array $input,
        ?string $instructions = null,
        array $options = []
    ): array {
        if ($this->apiFormat === 'gemini_native') {
            return $this->generateTextGeminiNative($model, $input, $instructions, $options);
        }
        if ($this->apiFormat === 'chat_completions') {
            return $this->generateTextChatCompletions($model, $input, $instructions, $options);
        }

        $resolvedModel = $this->resolveModelName($model);
        $options = $this->stripUnsupportedOptionsForModel($resolvedModel, $options);
        $payload = array_merge($options, [
            'model' => $resolvedModel,
            'input' => $input,
        ]);
        if ($instructions !== null && $instructions !== '') {
            $payload['instructions'] = $instructions;
        }

        $res = $this->createResponse($payload);

        // Responses часто возвращает удобное поле output_text (SDK-стайл),
        // но в raw JSON это может быть по-разному. Делаем безопасное извлечение.
        $outputText = $res['output_text'] ?? null;
        if (!is_string($outputText)) {
            $outputText = $this->extractOutputTextBestEffort($res);
        }

        return [
            'output_text' => $outputText,
            'raw' => $res,
            'cached_local' => !empty($res['_feedtools_cache']['hit']),
            'cache_key' => (string)($res['_feedtools_cache']['key'] ?? ''),
        ];
    }

    private function generateTextGeminiNative(
        string $model,
        string|array $input,
        ?string $instructions = null,
        array $options = []
    ): array {
        $noLocalCache = !empty($options['_feedtools_no_response_cache']);
        unset($options['_feedtools_no_response_cache']);

        $resolvedModel = $this->resolveModelName($model);
        $payload = $this->mapOptionsToGeminiGenerateContentPayload($resolvedModel, $input, $instructions, $options);
        $cacheKey = $this->responseCacheKey('POST', '/models/' . $resolvedModel . ':generateContent', $payload);
        $startedAt = hrtime(true);

        if (!$noLocalCache && $this->responseCacheEnabled) {
            $cached = $this->readResponseCache($cacheKey);
            if (is_array($cached)) {
                $marked = $this->markLocalCacheHit($cached, $cacheKey);
                $this->logOpenAIRequest(['model' => $resolvedModel] + $payload, $marked, null, $cacheKey, true, $startedAt, '/models:generateContent');
                $blockedReason = $this->geminiNativeBlockedReason($marked);
                if ($blockedReason !== '') {
                    $this->deleteResponseCache($cacheKey);
                } else {
                    return [
                        'output_text' => $this->extractOutputTextBestEffort($marked),
                        'raw' => $marked,
                        'cached_local' => true,
                        'cache_key' => $cacheKey,
                    ];
                }
            }
        }

        $url = $this->geminiNativeBaseUrl() . '/models/' . rawurlencode($resolvedModel) . ':generateContent?key=' . rawurlencode($this->apiKey);
        $headers = ['Content-Type: application/json'];
        foreach ($this->extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        try {
            $res = $this->requestWithRetriesUrl('POST', $url, $payload, $headers);
        } catch (Throwable $e) {
            $this->logOpenAIRequest(['model' => $resolvedModel] + $payload, null, $e, $cacheKey, false, $startedAt, '/models:generateContent');
            throw $e;
        }

        $res = $this->normalizeGeminiGenerateContentResponse($res);
        $this->logOpenAIRequest(['model' => $resolvedModel] + $payload, $res, null, $cacheKey, false, $startedAt, '/models:generateContent');
        $blockedReason = $this->geminiNativeBlockedReason($res);
        if ($blockedReason !== '') {
            $this->deleteResponseCache($cacheKey);
            throw new RuntimeException($blockedReason);
        }

        if (!$noLocalCache && $this->responseCacheEnabled) {
            $this->writeResponseCache($cacheKey, $res);
        }

        return [
            'output_text' => $this->extractOutputTextBestEffort($res),
            'raw' => $res,
            'cached_local' => false,
            'cache_key' => $cacheKey,
        ];
    }

    private function generateTextChatCompletions(
        string $model,
        string|array $input,
        ?string $instructions = null,
        array $options = []
    ): array {
        $noLocalCache = !empty($options['_feedtools_no_response_cache']);
        unset($options['_feedtools_no_response_cache']);

        $payload = $this->mapOptionsToChatCompletionsPayload($model, $input, $instructions, $options);
        $cacheKey = $this->responseCacheKey('POST', '/chat/completions', $payload);
        $startedAt = hrtime(true);

        if (!$noLocalCache && $this->responseCacheEnabled) {
            $cached = $this->readResponseCache($cacheKey);
            if (is_array($cached)) {
                $marked = $this->markLocalCacheHit($cached, $cacheKey);
                $this->logOpenAIRequest($payload, $marked, null, $cacheKey, true, $startedAt, '/chat/completions');
                $blockedReason = $this->chatCompletionBlockedReason($marked);
                if ($blockedReason !== '') {
                    $this->deleteResponseCache($cacheKey);
                } else {
                    return [
                        'output_text' => $this->extractOutputTextBestEffort($marked),
                        'raw' => $marked,
                        'cached_local' => true,
                        'cache_key' => $cacheKey,
                    ];
                }
            }
        }

        try {
            $res = $this->requestWithRetries('POST', '/chat/completions', $payload);
        } catch (Throwable $e) {
            $this->logOpenAIRequest($payload, null, $e, $cacheKey, false, $startedAt, '/chat/completions');
            throw $e;
        }

        $res = $this->normalizeChatCompletionResponse($res);
        $this->logOpenAIRequest($payload, $res, null, $cacheKey, false, $startedAt, '/chat/completions');
        $blockedReason = $this->chatCompletionBlockedReason($res);
        if ($blockedReason !== '') {
            $this->deleteResponseCache($cacheKey);
            throw new RuntimeException($blockedReason);
        }

        if (!$noLocalCache && $this->responseCacheEnabled) {
            $this->writeResponseCache($cacheKey, $res);
        }

        return [
            'output_text' => $this->extractOutputTextBestEffort($res),
            'raw' => $res,
            'cached_local' => false,
            'cache_key' => $cacheKey,
        ];
    }

    private function mapOptionsToChatCompletionsPayload(
        string $model,
        string|array $input,
        ?string $instructions,
        array $options
    ): array {
        $resolvedModel = $this->resolveModelName($model);
        $options = $this->stripUnsupportedOptionsForModel($resolvedModel, $options);
        $payload = [
            'model' => $resolvedModel,
            'messages' => $this->inputToChatMessages($input, $instructions),
        ];

        foreach (['temperature', 'top_p', 'presence_penalty', 'frequency_penalty', 'stop', 'seed'] as $key) {
            if (array_key_exists($key, $options)) {
                $payload[$key] = $options[$key];
            }
        }
        if (isset($options['max_output_tokens'])) {
            $payload['max_tokens'] = (int)$options['max_output_tokens'];
        } elseif (isset($options['max_tokens'])) {
            $payload['max_tokens'] = (int)$options['max_tokens'];
        }

        $text = $options['text'] ?? null;
        if (is_array($text) && (($text['format']['type'] ?? '') === 'json_object')) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        if (isset($options['response_format']) && is_array($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        return $payload;
    }

    private function mapOptionsToGeminiGenerateContentPayload(
        string $model,
        string|array $input,
        ?string $instructions,
        array $options
    ): array {
        $systemParts = [];
        $contents = $this->inputToGeminiContents($input, $instructions, $systemParts);
        $payload = [
            'contents' => $contents,
        ];
        if ($systemParts) {
            $payload['systemInstruction'] = ['parts' => $systemParts];
        }

        $generationConfig = [];
        if (array_key_exists('temperature', $options)) {
            $generationConfig['temperature'] = (float)$options['temperature'];
        }
        if (array_key_exists('top_p', $options)) {
            $generationConfig['topP'] = (float)$options['top_p'];
        }
        if (isset($options['max_output_tokens'])) {
            $generationConfig['maxOutputTokens'] = (int)$options['max_output_tokens'];
        } elseif (isset($options['max_tokens'])) {
            $generationConfig['maxOutputTokens'] = (int)$options['max_tokens'];
        }
        if (isset($options['stop'])) {
            $stop = is_array($options['stop']) ? array_values(array_map('strval', $options['stop'])) : [(string)$options['stop']];
            $generationConfig['stopSequences'] = array_values(array_filter($stop, static fn(string $s): bool => $s !== ''));
        }

        $text = $options['text'] ?? null;
        $responseFormat = $options['response_format'] ?? null;
        if ((is_array($text) && (($text['format']['type'] ?? '') === 'json_object'))
            || (is_array($responseFormat) && (($responseFormat['type'] ?? '') === 'json_object'))) {
            $generationConfig['responseMimeType'] = 'application/json';
        }

        $thinkingConfig = $this->geminiDefaultThinkingConfig($model);
        if ($thinkingConfig) {
            $generationConfig['thinkingConfig'] = $thinkingConfig;
        }

        if ($generationConfig) {
            $payload['generationConfig'] = $generationConfig;
        }

        return $payload;
    }

    private function inputToGeminiContents(string|array $input, ?string $instructions, array &$systemParts): array
    {
        $systemParts = [];
        if ($instructions !== null && trim($instructions) !== '') {
            $systemParts[] = ['text' => $instructions];
        }

        if (is_string($input)) {
            return [[
                'role' => 'user',
                'parts' => [['text' => $input]],
            ]];
        }

        $contents = [];
        foreach ($input as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string)($message['role'] ?? 'user');
            $parts = $this->geminiPartsFromContent($message['content'] ?? '');
            if (!$parts) {
                continue;
            }
            if ($role === 'system') {
                array_push($systemParts, ...$parts);
                continue;
            }
            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => $parts,
            ];
        }

        return $contents ?: [[
            'role' => 'user',
            'parts' => [['text' => '']],
        ]];
    }

    private function geminiPartsFromContent(mixed $content): array
    {
        if (is_string($content)) {
            return [['text' => $content]];
        }
        if (!is_array($content)) {
            return [['text' => (string)$content]];
        }

        $parts = [];
        foreach ($content as $part) {
            if (!is_array($part)) {
                $text = trim((string)$part);
                if ($text !== '') {
                    $parts[] = ['text' => $text];
                }
                continue;
            }
            $type = (string)($part['type'] ?? '');
            if ($type === 'input_text' || $type === 'text') {
                $parts[] = ['text' => (string)($part['text'] ?? '')];
            } elseif ($type === 'input_image' || $type === 'image_url') {
                $image = $part['image_url'] ?? '';
                $url = is_array($image) ? (string)($image['url'] ?? '') : (string)$image;
                if ($url !== '') {
                    $imagePart = $this->geminiImagePart($url);
                    if ($imagePart) {
                        $parts[] = $imagePart;
                    }
                }
            } elseif (isset($part['text'])) {
                $parts[] = ['text' => (string)$part['text']];
            }
        }

        return $parts;
    }

    private function geminiImagePart(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('/^data:image\\/([^;]+);base64,(.*)$/s', $url, $m)) {
            $url = $this->downloadImageAsDataUrl($url);
            if (!preg_match('/^data:image\\/([^;]+);base64,(.*)$/s', $url, $m)) {
                return null;
            }
        }
        return [
            'inlineData' => [
                'mimeType' => 'image/' . strtolower((string)$m[1]),
                'data' => (string)$m[2],
            ],
        ];
    }

    private function geminiDefaultThinkingConfig(string $model): array
    {
        $norm = strtolower($model);
        if (str_contains($norm, 'gemini-3') && str_contains($norm, 'pro')) {
            return ['thinkingLevel' => 'low'];
        }
        if (str_contains($norm, 'gemini-3-flash')) {
            return ['thinkingLevel' => 'minimal'];
        }
        if (str_contains($norm, 'gemini-2.5-flash')) {
            return ['thinkingBudget' => 0];
        }
        return [];
    }

    private function geminiNativeBaseUrl(): string
    {
        $base = rtrim($this->baseUrl, '/');
        if (str_ends_with($base, '/openai')) {
            $base = substr($base, 0, -7);
        }
        return rtrim($base, '/');
    }

    private function inputToChatMessages(string|array $input, ?string $instructions): array
    {
        $messages = [];
        if ($instructions !== null && trim($instructions) !== '') {
            $messages[] = ['role' => 'system', 'content' => $instructions];
        }

        if (is_string($input)) {
            $messages[] = ['role' => 'user', 'content' => $input];
            return $messages;
        }

        foreach ($input as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string)($message['role'] ?? 'user');
            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                $role = 'user';
            }
            $content = $message['content'] ?? '';
            $messages[] = [
                'role' => $role,
                'content' => $this->responsesContentToChatContent($content),
            ];
        }
        if (!$messages || ($instructions !== null && count($messages) === 1)) {
            $messages[] = ['role' => 'user', 'content' => ''];
        }
        return $messages;
    }

    private function responsesContentToChatContent(mixed $content): mixed
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return (string)$content;
        }

        $parts = [];
        foreach ($content as $part) {
            if (!is_array($part)) {
                $text = trim((string)$part);
                if ($text !== '') {
                    $parts[] = ['type' => 'text', 'text' => $text];
                }
                continue;
            }
            $type = (string)($part['type'] ?? '');
            if ($type === 'input_text' || $type === 'text') {
                $parts[] = ['type' => 'text', 'text' => (string)($part['text'] ?? '')];
            } elseif ($type === 'input_image' || $type === 'image_url') {
                $image = $part['image_url'] ?? '';
                $url = is_array($image) ? (string)($image['url'] ?? '') : (string)$image;
                if ($url !== '') {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $this->chatImageUrl($url)]];
                }
            }
        }
        return $parts ?: '';
    }

    private function chatImageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !$this->providerNeedsInlineImageUrls()) {
            return $url;
        }
        if (preg_match('/^data:image\\//i', $url)) {
            return $url;
        }
        if (!preg_match('/^https?:\\/\\//i', $url)) {
            return $url;
        }
        return $this->downloadImageAsDataUrl($url);
    }

    private function providerNeedsInlineImageUrls(): bool
    {
        return strtolower(trim($this->provider)) === 'yandex';
    }

    private function downloadImageAsDataUrl(string $url): string
    {
        $bytes = '';
        $tooLarge = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->remoteImageTimeoutSec),
            CURLOPT_TIMEOUT => min($this->timeoutSec, $this->remoteImageTimeoutSec),
            CURLOPT_SSL_VERIFYPEER => $this->tlsVerify,
            CURLOPT_SSL_VERIFYHOST => $this->tlsVerify ? 2 : 0,
            CURLOPT_USERAGENT => 'FeedTools LLM image fetcher',
            CURLOPT_HTTPHEADER => [
                'Accept: image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8,*/*;q=0.1',
            ],
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$bytes, &$tooLarge): int {
                if (strlen($bytes) + strlen($chunk) > $this->remoteImageMaxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $bytes .= $chunk;
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($tooLarge) {
            throw new RuntimeException('Image is too large for inline LLM upload: ' . $url);
        }
        if ($ok === false) {
            throw new RuntimeException('Image fetch failed for LLM upload: ' . $err);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Image fetch HTTP ' . $status . ' for LLM upload: ' . $url);
        }
        if ($bytes === '') {
            throw new RuntimeException('Image fetch returned empty body for LLM upload: ' . $url);
        }

        $mime = strtolower(trim(explode(';', $contentType)[0] ?? ''));
        if (!str_starts_with($mime, 'image/')) {
            $detected = $this->detectImageMime($bytes, $url);
            if ($detected !== '') {
                $mime = $detected;
            }
        }
        if (!str_starts_with($mime, 'image/')) {
            throw new RuntimeException('Fetched URL is not an image for LLM upload: ' . $url);
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    private function detectImageMime(string $bytes, string $url): string
    {
        if (function_exists('finfo_buffer')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = strtolower((string)@finfo_buffer($finfo, $bytes));
                @finfo_close($finfo);
                if (str_starts_with($mime, 'image/')) {
                    return $mime;
                }
            }
        }

        $path = parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            default => '',
        };
    }

    private function normalizeChatCompletionResponse(array $res): array
    {
        $text = $this->extractOutputTextBestEffort($res);
        if ($text !== '') {
            $res['output_text'] = $text;
        }
        if (isset($res['usage']) && is_array($res['usage'])) {
            $usage = $res['usage'];
            if (!isset($usage['input_tokens']) && isset($usage['prompt_tokens'])) {
                $usage['input_tokens'] = (int)$usage['prompt_tokens'];
            }
            if (!isset($usage['output_tokens']) && isset($usage['completion_tokens'])) {
                $usage['output_tokens'] = (int)$usage['completion_tokens'];
            }
            if (!isset($usage['total_tokens'])) {
                $usage['total_tokens'] = (int)($usage['input_tokens'] ?? 0) + (int)($usage['output_tokens'] ?? 0);
            }
            if (!isset($usage['input_tokens_details']) && isset($usage['prompt_tokens_details']) && is_array($usage['prompt_tokens_details'])) {
                $usage['input_tokens_details'] = $usage['prompt_tokens_details'];
            }
            if (!isset($usage['input_tokens_details']) || !is_array($usage['input_tokens_details'])) {
                $usage['input_tokens_details'] = ['cached_tokens' => 0, 'cached_input_tokens' => 0];
            }
            $res['usage'] = $usage;
        }
        return $res;
    }

    private function normalizeGeminiGenerateContentResponse(array $res): array
    {
        $text = $this->extractOutputTextBestEffort($res);
        if ($text !== '') {
            $res['output_text'] = $text;
        }

        $usage = $res['usageMetadata'] ?? null;
        if (is_array($usage)) {
            $input = (int)($usage['promptTokenCount'] ?? 0);
            $candidate = (int)($usage['candidatesTokenCount'] ?? 0);
            $thoughts = (int)($usage['thoughtsTokenCount'] ?? 0);
            $total = (int)($usage['totalTokenCount'] ?? ($input + $candidate + $thoughts));
            $output = max($candidate + $thoughts, $total - $input);
            $cached = (int)($usage['cachedContentTokenCount'] ?? 0);
            $res['usage'] = [
                'input_tokens' => max(0, $input),
                'output_tokens' => max(0, $output),
                'total_tokens' => max(0, $total),
                'input_tokens_details' => [
                    'cached_tokens' => max(0, $cached),
                    'cached_input_tokens' => max(0, $cached),
                ],
                'gemini_candidates_tokens' => max(0, $candidate),
                'gemini_thoughts_tokens' => max(0, $thoughts),
            ];
        }

        return $res;
    }

    private function geminiNativeBlockedReason(array $res): string
    {
        $reasons = [];
        $promptFeedback = $res['promptFeedback'] ?? null;
        if (is_array($promptFeedback)) {
            $block = trim((string)($promptFeedback['blockReason'] ?? ''));
            if ($block !== '') {
                $reasons[] = $block;
            }
        }
        foreach ((array)($res['candidates'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $finish = strtoupper(trim((string)($candidate['finishReason'] ?? '')));
            if (in_array($finish, ['SAFETY', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'], true)) {
                $reasons[] = $finish;
            }
        }

        if (!$reasons) {
            return '';
        }

        $message = 'gemini заблокировал ответ фильтром безопасности (' . implode(', ', array_unique($reasons)) . ').';
        $text = trim($this->extractOutputTextBestEffort($res));
        if ($text !== '') {
            $message .= ' Ответ провайдера: ' . mb_substr($text, 0, 300, 'UTF-8');
        }
        return $message;
    }

    private function chatCompletionBlockedReason(array $res): string
    {
        $blockedReasons = [];
        foreach ((array)($res['choices'] ?? []) as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $finishReason = strtolower(trim((string)($choice['finish_reason'] ?? '')));
            if (in_array($finishReason, ['content_filter', 'safety', 'blocked', 'prohibited_content'], true)) {
                $blockedReasons[] = $finishReason;
            }
        }

        if (!$blockedReasons) {
            return '';
        }

        $provider = $this->provider !== '' ? $this->provider : 'LLM provider';
        $text = trim($this->extractOutputTextBestEffort($res));
        $message = $provider . ' заблокировал ответ фильтром безопасности (' . implode(', ', array_unique($blockedReasons)) . ').';
        if ($text !== '') {
            $message .= ' Ответ провайдера: ' . mb_substr($text, 0, 300, 'UTF-8');
        }
        return $message;
    }

    private function applyPromptCachingOptions(array $payload): array
    {
        if ($this->promptCacheKeyEnabled && empty($payload['prompt_cache_key'])) {
            $payload['prompt_cache_key'] = $this->makePromptCacheKey($payload);
        }
        if (!empty($payload['prompt_cache_key'])) {
            $payload['prompt_cache_key'] = $this->normalizePromptCacheKey((string)$payload['prompt_cache_key']);
        }

        if ($this->promptCacheRetention !== '' && empty($payload['prompt_cache_retention'])) {
            $retention = $this->promptCacheRetention;
            if ($retention === 'auto') {
                $model = trim((string)($payload['model'] ?? ''));
                $retention = openai_model_supports_extended_prompt_cache($model) ? '24h' : '';
            }
            if ($retention !== '') {
                $payload['prompt_cache_retention'] = $retention;
            }
        }

        return $payload;
    }

    private function applyImagePromptCachingOptions(array $fields, array $options): array
    {
        if (array_key_exists('prompt_cache_key', $options)) {
            $key = trim((string)$options['prompt_cache_key']);
            if ($key !== '') {
                $fields['prompt_cache_key'] = $this->normalizePromptCacheKey($key);
            }
        }

        if (array_key_exists('prompt_cache_retention', $options)) {
            $retention = trim((string)$options['prompt_cache_retention']);
            if ($retention !== '') {
                $fields['prompt_cache_retention'] = $retention;
            }
        }

        return $fields;
    }

    private function hasImagePromptCachingOptions(array $fields): bool
    {
        return isset($fields['prompt_cache_key']) || isset($fields['prompt_cache_retention']);
    }

    private function stripImagePromptCachingOptions(array $fields): array
    {
        unset($fields['prompt_cache_key'], $fields['prompt_cache_retention']);
        return $fields;
    }

    private function isUnsupportedPromptCachingOptionError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        if (!str_contains($message, 'prompt_cache_key') && !str_contains($message, 'prompt_cache_retention')) {
            return false;
        }

        return str_contains($message, 'unknown')
            || str_contains($message, 'unrecognized')
            || str_contains($message, 'unsupported')
            || str_contains($message, 'invalid parameter')
            || str_contains($message, 'extra inputs are not permitted');
    }

    private function makePromptCacheKey(array $payload): string
    {
        $prefix = $payload;
        unset(
            $prefix['input'],
            $prefix['prompt_cache_key'],
            $prefix['prompt_cache_retention'],
            $prefix['_feedtools_no_response_cache']
        );

        $json = json_encode($this->canonicalize($prefix), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) $json = '';

        return 'ft_' . substr(hash('sha256', $json), 0, 48);
    }

    private function normalizePromptCacheKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return 'ft_' . substr(hash('sha256', 'empty'), 0, 48);
        }

        $key = preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $key) ?? $key;
        if (strlen($key) <= 64) {
            return $key;
        }

        return substr($key, 0, 12) . ':' . substr(hash('sha256', $key), 0, 51);
    }

    private function responseCacheKey(string $method, string $path, array $payload): string
    {
        $body = [
            'cache_version' => 2,
            'method' => strtoupper($method),
            'base_url' => $this->baseUrl,
            'path' => $path,
            'payload' => $this->canonicalize($payload),
        ];
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('OpenAI cache key json_encode failed');
        }
        return hash('sha256', $json);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn($item) => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $k => $v) {
            $value[$k] = $this->canonicalize($v);
        }
        return $value;
    }

    private function responseCacheFile(string $cacheKey): string
    {
        $prefix = substr($cacheKey, 0, 2);
        return $this->responseCacheDir . '/' . $prefix . '/' . $cacheKey . '.json';
    }

    private function ensureResponseCacheDir(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create OpenAI response cache dir: ' . $dir);
        }
    }

    private function readResponseCache(string $cacheKey): ?array
    {
        $file = $this->responseCacheFile($cacheKey);
        if (!is_file($file)) {
            return null;
        }

        if ($this->responseCacheTtlSec > 0) {
            $age = time() - (int)filemtime($file);
            if ($age > $this->responseCacheTtlSec) {
                @unlink($file);
                return null;
            }
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || (int)($decoded['version'] ?? 0) !== 1) {
            return null;
        }

        $response = $decoded['response'] ?? null;
        return is_array($response) ? $response : null;
    }

    private function writeResponseCache(string $cacheKey, array $response): void
    {
        $file = $this->responseCacheFile($cacheKey);
        try {
            $this->ensureResponseCacheDir($file);
        } catch (Throwable $e) {
            return;
        }

        $payload = [
            'version' => 1,
            'created_at' => date('c'),
            'cache_key' => $cacheKey,
            'response' => $response,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return;

        $tmp = $file . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(3));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return;
        }
        @rename($tmp, $file);
    }

    private function deleteResponseCache(string $cacheKey): void
    {
        $file = $this->responseCacheFile($cacheKey);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function markLocalCacheHit(array $response, string $cacheKey): array
    {
        $cacheMeta = [
            'hit' => true,
            'key' => $cacheKey,
            'source' => 'local_response_cache',
            'hit_at' => date('c'),
        ];

        if (isset($response['usage']) && is_array($response['usage'])) {
            $cacheMeta['original_usage'] = $response['usage'];
            $response['usage']['input_tokens'] = 0;
            $response['usage']['output_tokens'] = 0;
            $response['usage']['total_tokens'] = 0;
            $response['usage']['input_tokens_details'] = [
                'cached_tokens' => 0,
                'cached_input_tokens' => 0,
            ];
        }

        $response['_feedtools_cache'] = $cacheMeta;
        return $response;
    }

    private function logOpenAIRequest(
        array $payload,
        ?array $response,
        ?Throwable $error,
        string $cacheKey,
        bool $localCacheHit,
        int $startedAt,
        string $endpoint = '/responses'
    ): void {
        if (!$this->requestLogEnabled) return;

        $durationMs = (int)max(0, round((hrtime(true) - $startedAt) / 1_000_000));
        $usage = $this->extractUsageForLog($response);
        $outputText = '';
        if (is_array($response)) {
            $outputText = $response['output_text'] ?? null;
            if (!is_string($outputText)) {
                $outputText = $this->extractOutputTextBestEffort($response);
            }
        }

        OpenAIRequestLog::write([
            'model' => (string)($payload['model'] ?? ''),
            'endpoint' => $endpoint,
            'status' => $error ? 'error' : 'ok',
            'duration_ms' => $durationMs,
            'local_cache_hit' => $localCacheHit,
            'cache_key' => $cacheKey,
            'prompt_cache_key' => (string)($payload['prompt_cache_key'] ?? ''),
            'prompt_cache_retention' => (string)($payload['prompt_cache_retention'] ?? ''),
            'input_tokens' => $usage['input_tokens'],
            'cached_input_tokens' => $usage['cached_input_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'total_tokens' => $usage['total_tokens'],
            'request_payload' => $this->redactPayloadForLog($payload),
            'response_text' => $outputText,
            'response_payload' => $response,
            'error_text' => $error ? $this->redactSecretsForLog($error->getMessage()) : '',
        ], $this->requestLogMaxRequestChars, $this->requestLogMaxResponseChars);
    }

    private function redactSecretsForLog(string $text): string
    {
        if ($text === '') {
            return '';
        }
        return preg_replace('~sk-[A-Za-z0-9_\\-*]{6,}~', 'sk-***', $text) ?? $text;
    }

    private function redactPayloadForLog(mixed $value): mixed
    {
        if (is_string($value)) {
            if (preg_match('/^data:image\\/([^;]+);base64,(.*)$/s', $value, $m)) {
                return 'data:image/' . $m[1] . ';base64,[redacted ' . strlen($m[2]) . ' chars]';
            }
            return $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->redactPayloadForLog($v);
        }
        return $out;
    }

    private function extractUsageForLog(?array $response): array
    {
        $out = [
            'input_tokens' => 0,
            'cached_input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
        ];

        if (!$response || !isset($response['usage']) || !is_array($response['usage'])) {
            return $out;
        }

        $usage = $response['usage'];
        $out['input_tokens'] = max(0, (int)($usage['input_tokens'] ?? 0));
        $out['output_tokens'] = max(0, (int)($usage['output_tokens'] ?? 0));
        $out['total_tokens'] = max(0, (int)($usage['total_tokens'] ?? ($out['input_tokens'] + $out['output_tokens'])));

        $out['cached_input_tokens'] = max(0, (int)($usage['cached_input_tokens'] ?? ($usage['cached_tokens'] ?? 0)));

        $details = $usage['input_tokens_details'] ?? null;
        if (is_array($details)) {
            $out['cached_input_tokens'] = max(
                $out['cached_input_tokens'],
                (int)($details['cached_tokens'] ?? ($details['cached_input_tokens'] ?? 0))
            );
        }
        $out['cached_input_tokens'] = min($out['input_tokens'], $out['cached_input_tokens']);

        return $out;
    }

    private function writeTempImageFile(string $bytes, string $mimeType): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'feedtools-openai-image-');
        if (!is_string($tmp) || $tmp === '') {
            throw new RuntimeException('Cannot create temporary image file.');
        }
        $path = $tmp . '.' . $this->extensionForMime($mimeType);
        if (!@rename($tmp, $path)) {
            $path = $tmp;
        }
        if (@file_put_contents($path, $bytes) === false) {
            @unlink($path);
            throw new RuntimeException('Cannot write temporary image file.');
        }
        return $path;
    }

    private function extensionForMime(string $mimeType): string
    {
        return match (strtolower(trim($mimeType))) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
    }

    private function sanitizeImageApiResponseForLog(array $response): array
    {
        $copy = $response;
        foreach ((array)($copy['data'] ?? []) as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (['b64_json', 'revised_prompt'] as $key) {
                $value = (string)($item[$key] ?? '');
                if ($value === '') {
                    continue;
                }
                $copy['data'][$i][$key . '_length'] = strlen($value);
                $copy['data'][$i][$key . '_sha256'] = hash('sha256', $value);
                unset($copy['data'][$i][$key]);
            }
        }
        return $copy;
    }

    private function sanitizeVideoApiResponseForLog(array $response): array
    {
        $copy = $response;
        if (isset($copy['error']) && is_array($copy['error'])) {
            $message = (string)($copy['error']['message'] ?? '');
            if ($message !== '') {
                $copy['error']['message'] = $this->redactSecretsForLog($message);
            }
        }
        return $copy;
    }

    private function sanitizeResponsesImageCallForLog(array $item): array
    {
        $copy = $item;
        foreach (['result', 'revised_prompt'] as $key) {
            $value = (string)($copy[$key] ?? '');
            if ($value === '') {
                continue;
            }
            $copy[$key . '_length'] = strlen($value);
            $copy[$key . '_sha256'] = hash('sha256', $value);
            unset($copy[$key]);
        }
        return $copy;
    }

    private function sanitizeResponsesApiResponseForLog(array $response): array
    {
        $copy = $response;
        foreach ((array)($copy['output'] ?? []) as $i => $item) {
            if (!is_array($item) || (string)($item['type'] ?? '') !== 'image_generation_call') {
                continue;
            }
            $copy['output'][$i] = $this->sanitizeResponsesImageCallForLog($item);
        }
        return $copy;
    }

    private function requestWithRetries(string $method, string $path, array $jsonBody): array
    {
        return $this->requestWithRetriesUrl($method, $this->baseUrl . $path, $jsonBody, $this->buildHeaders('application/json'));
    }

    private function requestMultipartWithRetries(string $method, string $path, array $fields): array
    {
        $attempt = 0;
        $lastErr = null;
        $url = $this->baseUrl . $path;
        $headers = $this->buildHeaders('');

        while ($attempt <= $this->retries) {
            $attempt++;

            try {
                [$status, $body] = $this->httpMultipartWithHeaders($method, $url, $fields, $headers);
                if ($status >= 200 && $status < 300) {
                    return $body;
                }
                if (in_array($status, [408, 429], true) || ($status >= 500 && $status <= 599)) {
                    $lastErr = new RuntimeException($this->redactSecretsForLog("LLM HTTP $status: " . json_encode($body, JSON_UNESCAPED_UNICODE)));
                    if ($attempt <= $this->retries) {
                        $this->sleepBackoff($attempt);
                        continue;
                    }
                    break;
                }
                throw new RuntimeException($this->redactSecretsForLog("LLM HTTP $status: " . json_encode($body, JSON_UNESCAPED_UNICODE)));
            } catch (Throwable $e) {
                $lastErr = $e;
                if ($this->isNonRetryableHttpError($e)) {
                    break;
                }
                if ($attempt <= $this->retries) {
                    $this->sleepBackoff($attempt);
                    continue;
                }
                break;
            }
        }

        throw new RuntimeException('LLM request failed after retries: ' . $this->redactSecretsForLog($lastErr?->getMessage() ?? 'unknown'));
    }

    private function requestNoBodyWithRetries(string $method, string $path): array
    {
        $attempt = 0;
        $lastErr = null;
        $url = $this->baseUrl . $path;
        $headers = $this->buildHeaders('');

        while ($attempt <= $this->retries) {
            $attempt++;

            try {
                [$status, $body] = $this->httpNoBodyWithHeaders($method, $url, $headers);
                if ($status >= 200 && $status < 300) {
                    return $body;
                }
                if (in_array($status, [408, 429], true) || ($status >= 500 && $status <= 599)) {
                    $lastErr = new RuntimeException($this->redactSecretsForLog("LLM HTTP $status: " . json_encode($body, JSON_UNESCAPED_UNICODE)));
                    if ($attempt <= $this->retries) {
                        $this->sleepBackoff($attempt);
                        continue;
                    }
                    break;
                }
                throw new RuntimeException($this->redactSecretsForLog("LLM HTTP $status: " . json_encode($body, JSON_UNESCAPED_UNICODE)));
            } catch (Throwable $e) {
                $lastErr = $e;
                if ($this->isNonRetryableHttpError($e)) {
                    break;
                }
                if ($attempt <= $this->retries) {
                    $this->sleepBackoff($attempt);
                    continue;
                }
                break;
            }
        }

        throw new RuntimeException('LLM request failed after retries: ' . $this->redactSecretsForLog($lastErr?->getMessage() ?? 'unknown'));
    }

    private function requestBinaryNoBodyWithRetries(string $method, string $path): string
    {
        $attempt = 0;
        $lastErr = null;
        $url = $this->baseUrl . $path;
        $headers = $this->buildHeaders('');

        while ($attempt <= $this->retries) {
            $attempt++;

            try {
                [$status, $body] = $this->httpBinaryNoBodyWithHeaders($method, $url, $headers);
                if ($status >= 200 && $status < 300) {
                    return $body;
                }
                $decoded = json_decode($body, true);
                $bodyForError = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : substr($body, 0, 1000);
                if (in_array($status, [408, 429], true) || ($status >= 500 && $status <= 599)) {
                    $lastErr = new RuntimeException($this->redactSecretsForLog("LLM HTTP $status: " . $bodyForError));
                    if ($attempt <= $this->retries) {
                        $this->sleepBackoff($attempt);
                        continue;
                    }
                    break;
                }
                throw new RuntimeException($this->redactSecretsForLog("LLM HTTP $status: " . $bodyForError));
            } catch (Throwable $e) {
                $lastErr = $e;
                if ($this->isNonRetryableHttpError($e)) {
                    break;
                }
                if ($attempt <= $this->retries) {
                    $this->sleepBackoff($attempt);
                    continue;
                }
                break;
            }
        }

        throw new RuntimeException('LLM request failed after retries: ' . $this->redactSecretsForLog($lastErr?->getMessage() ?? 'unknown'));
    }

    private function requestWithRetriesUrl(string $method, string $url, array $jsonBody, array $headers): array
    {
        $attempt = 0;
        $lastErr = null;

        while ($attempt <= $this->retries) {
            $attempt++;

            try {
                [$status, $body] = $this->httpJsonWithHeaders($method, $url, $jsonBody, $headers);

                // Успех
                if ($status >= 200 && $status < 300) {
                    return $body;
                }

                // Ретраим на временных: 408, 429, 5xx
                if (in_array($status, [408, 429], true) || ($status >= 500 && $status <= 599)) {
                    $lastErr = new RuntimeException($this->redactSecretsForLog("LLM HTTP $status: " . json_encode($body, JSON_UNESCAPED_UNICODE)));
                    if ($attempt <= $this->retries) {
                        $this->sleepBackoff($attempt);
                        continue;
                    }
                    break;
                }

                throw new RuntimeException($this->redactSecretsForLog("LLM HTTP $status: " . json_encode($body, JSON_UNESCAPED_UNICODE)));

            } catch (Throwable $e) {
                $lastErr = $e;
                if ($this->isNonRetryableHttpError($e)) {
                    break;
                }

                // На сетевых/таймаутах тоже ретраим
                if ($attempt <= $this->retries) {
                    $this->sleepBackoff($attempt);
                    continue;
                }
                break;
            }
        }

        throw new RuntimeException('LLM request failed after retries: ' . $this->redactSecretsForLog($lastErr?->getMessage() ?? 'unknown'));
    }

    private function isNonRetryableHttpError(Throwable $e): bool
    {
        $msg = $e->getMessage();
        if (!preg_match('/OpenAI HTTP (\d{3})|LLM HTTP (\d{3})/', $msg, $m)) {
            return false;
        }
        $status = (int)(((string)($m[1] ?? '')) !== '' ? $m[1] : ($m[2] ?? 0));
        return $status >= 400 && $status < 500 && !in_array($status, [408, 429], true);
    }

    private function sleepBackoff(int $attempt): void
    {
        // экспоненциальный + джиттер
        $base = $this->retryBaseDelayMs * (2 ** max(0, $attempt - 1));
        $jitter = random_int(0, (int)($base * 0.25));
        usleep(($base + $jitter) * 1000);
    }

    private function httpJson(string $method, string $url, array $jsonBody): array
    {
        return $this->httpJsonWithHeaders($method, $url, $jsonBody, $this->buildHeaders('application/json'));
    }

    private function httpJsonWithHeaders(string $method, string $url, array $jsonBody, array $headers): array
    {
        $ch = curl_init($url);

        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new RuntimeException('json_encode failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $this->timeoutSec)),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->tlsVerify,
            CURLOPT_SSL_VERIFYHOST => $this->tlsVerify ? 2 : 0,
        ]);
        $this->applyIpResolveOption($ch);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            if (PHP_VERSION_ID < 80500) {
    curl_close($ch);
}
            throw new RuntimeException('curl error: ' . $err);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        if (PHP_VERSION_ID < 80500) {
    curl_close($ch);
}

        $bodyStr = substr($raw, $headerSize);
        $decoded = json_decode($bodyStr, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("Bad JSON from LLM provider (HTTP $status): " . substr($bodyStr, 0, 1000));
        }

        return [$status, $decoded];
    }

    private function httpMultipartWithHeaders(string $method, string $url, array $fields, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $this->timeoutSec)),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->tlsVerify,
            CURLOPT_SSL_VERIFYHOST => $this->tlsVerify ? 2 : 0,
        ]);
        $this->applyIpResolveOption($ch);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            throw new RuntimeException('curl error: ' . $err);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        $bodyStr = substr($raw, $headerSize);
        $decoded = json_decode($bodyStr, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Bad JSON from LLM provider (HTTP $status): " . substr($bodyStr, 0, 1000));
        }

        return [$status, $decoded];
    }

    private function httpNoBodyWithHeaders(string $method, string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $this->timeoutSec)),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->tlsVerify,
            CURLOPT_SSL_VERIFYHOST => $this->tlsVerify ? 2 : 0,
        ]);
        $this->applyIpResolveOption($ch);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            throw new RuntimeException('curl error: ' . $err);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        $bodyStr = substr($raw, $headerSize);
        $decoded = json_decode($bodyStr, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Bad JSON from LLM provider (HTTP $status): " . substr($bodyStr, 0, 1000));
        }

        return [$status, $decoded];
    }

    private function httpBinaryNoBodyWithHeaders(string $method, string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $this->timeoutSec)),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->tlsVerify,
            CURLOPT_SSL_VERIFYHOST => $this->tlsVerify ? 2 : 0,
        ]);
        $this->applyIpResolveOption($ch);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            throw new RuntimeException('curl error: ' . $err);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        return [$status, substr($raw, $headerSize)];
    }

    private function buildHeaders(string $contentType): array
    {
        $headers = [];
        if (trim($contentType) !== '') {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        foreach ($this->extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $authType = strtolower($this->authType);
        if ($authType === 'none') {
            return $headers;
        }

        if ($authType === 'gigachat_oauth') {
            $headers[] = 'Authorization: Bearer ' . $this->getGigaChatAccessToken();
            return $headers;
        }

        if ($authType === 'api_key') {
            $value = $this->authValuePrefix !== ''
                ? ($this->authValuePrefix . ' ' . $this->apiKey)
                : $this->apiKey;
            $headers[] = $this->authHeader . ': ' . $value;
            return $headers;
        }

        $header = $this->authHeader ?: 'Authorization';
        $prefix = $this->authValuePrefix !== '' ? $this->authValuePrefix : 'Bearer';
        if (strcasecmp($header, 'Authorization') === 0) {
            $headers[] = 'Authorization: ' . $prefix . ' ' . $this->apiKey;
        } else {
            $headers[] = $header . ': ' . $prefix . ' ' . $this->apiKey;
        }
        return $headers;
    }

    private function getGigaChatAccessToken(): string
    {
        $cached = $this->readOauthTokenCache();
        if ($cached !== '') {
            return $cached;
        }
        if ($this->apiKey === '') {
            throw new RuntimeException('GigaChat authorization key is not set');
        }
        if ($this->oauthUrl === '') {
            throw new RuntimeException('GigaChat OAuth URL is not set');
        }

        $ch = curl_init($this->oauthUrl);
        $body = http_build_query(['scope' => $this->oauthScope]);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $this->apiKey,
                'RqUID: ' . $this->uuid4(),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => $this->tlsVerify,
            CURLOPT_SSL_VERIFYHOST => $this->tlsVerify ? 2 : 0,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            throw new RuntimeException('GigaChat OAuth curl error: ' . $err);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
        $bodyStr = substr($raw, $headerSize);
        $decoded = json_decode($bodyStr, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new RuntimeException("GigaChat OAuth HTTP {$status}: " . substr($bodyStr, 0, 1000));
        }
        $token = trim((string)($decoded['access_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('GigaChat OAuth returned empty access_token');
        }
        $expiresAtMs = (int)($decoded['expires_at'] ?? 0);
        $expiresAt = $expiresAtMs > 0 ? (int)floor($expiresAtMs / 1000) : (time() + 25 * 60);
        $this->writeOauthTokenCache($token, $expiresAt);
        return $token;
    }

    private function readOauthTokenCache(): string
    {
        $file = $this->oauthCacheFile;
        if ($file === '' || !is_file($file)) {
            return '';
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '';
        }
        $token = trim((string)($decoded['access_token'] ?? ''));
        $expiresAt = (int)($decoded['expires_at'] ?? 0);
        if ($token === '' || $expiresAt <= time() + 60) {
            return '';
        }
        return $token;
    }

    private function writeOauthTokenCache(string $token, int $expiresAt): void
    {
        $file = $this->oauthCacheFile;
        if ($file === '') {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = [
            'access_token' => $token,
            'expires_at' => $expiresAt,
            'created_at' => date('c'),
            'provider' => $this->provider,
        ];
        @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function extractOutputTextBestEffort(array $res): string
    {
        // Best-effort для разных форматов ответов.
        if (isset($res['choices']) && is_array($res['choices'])) {
            $texts = [];
            foreach ($res['choices'] as $choice) {
                if (!is_array($choice)) {
                    continue;
                }
                $message = $choice['message'] ?? null;
                if (is_array($message)) {
                    $content = $message['content'] ?? '';
                    if (is_string($content)) {
                        $texts[] = $content;
                    } elseif (is_array($content)) {
                        foreach ($content as $part) {
                            if (is_array($part) && isset($part['text'])) {
                                $texts[] = (string)$part['text'];
                            }
                        }
                    }
                }
                if (isset($choice['text']) && is_string($choice['text'])) {
                    $texts[] = $choice['text'];
                }
            }
            $joined = trim(implode("\n", $texts));
            if ($joined !== '') return $joined;
        }

        if (isset($res['output']) && is_array($res['output'])) {
            $texts = [];
            foreach ($res['output'] as $item) {
                if (!is_array($item)) continue;
                // часто бывает message -> content[] -> text
                if (($item['type'] ?? null) === 'message' && isset($item['content']) && is_array($item['content'])) {
                    foreach ($item['content'] as $c) {
                        if (is_array($c) && isset($c['text'])) {
                            $texts[] = (string)$c['text'];
                        }
                    }
                }
            }
            $joined = trim(implode("\n", $texts));
            if ($joined !== '') return $joined;
        }

        if (isset($res['candidates']) && is_array($res['candidates'])) {
            $texts = [];
            foreach ($res['candidates'] as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $parts = $candidate['content']['parts'] ?? null;
                if (!is_array($parts)) {
                    continue;
                }
                foreach ($parts as $part) {
                    if (is_array($part) && isset($part['text'])) {
                        $texts[] = (string)$part['text'];
                    }
                }
            }
            $joined = trim(implode("\n", $texts));
            if ($joined !== '') return $joined;
        }
        return '';
    }
}
