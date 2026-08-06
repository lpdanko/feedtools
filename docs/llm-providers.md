# LLM providers

FeedTools now uses a generic LLM configuration layer. OpenAI is still supported,
but GPT operations can also use OpenAI-compatible `/chat/completions` providers.
The UI model dropdown can contain models from several providers at once:
FeedTools routes requests by the selected model name.

## Common variables

```env
LLM_PROVIDER=custom
LLM_API_FORMAT=chat_completions
LLM_API_KEY=...
LLM_BASE_URL=https://provider.example/v1
LLM_MODEL_DEFAULT=model-name
LLM_MODELS=model-name,another-model
LLM_MODEL_PREFIX=
LLM_AUTH_TYPE=bearer
LLM_AUTH_HEADER=Authorization
LLM_IP_RESOLVE=
```

`LLM_API_FORMAT=responses` should be used only for OpenAI Responses API.
Most alternative providers use `LLM_API_FORMAT=chat_completions`.

`LLM_MODEL_PREFIX` is optional. FeedTools shows short model names in the UI,
but sends `LLM_MODEL_PREFIX + "/" + selected_model` to the provider. This is
useful for providers where the request model must be a URI.

`LLM_IP_RESOLVE` can be set to `v4` or `v6` to force cURL to use IPv4 or IPv6
for LLM provider requests. Provider-specific settings, such as
`GEMINI_IP_RESOLVE`, override the common value.

Provider-specific model lists are merged into the dropdown. For example,
`gpt-*` models are routed to OpenAI, while `yandexgpt-*`, `aliceai-*`, and
`gemma-*` models are routed to Yandex.

## OpenAI

```env
OPENAI_API_KEY=<api-key>
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_MODEL_DEFAULT=gpt-5.4
OPENAI_MODELS=gpt-5.5,gpt-5.4,gpt-5.3
```

## GigaChat

```env
LLM_PROVIDER=gigachat
LLM_API_FORMAT=chat_completions
LLM_BASE_URL=https://gigachat.devices.sberbank.ru/api/v1
LLM_AUTH_TYPE=gigachat_oauth
LLM_API_KEY=<authorization-key>
LLM_MODEL_DEFAULT=GigaChat-2-Max
LLM_MODELS=GigaChat-2-Max,GigaChat-2-Pro,GigaChat-2
LLM_OAUTH_SCOPE=GIGACHAT_API_PERS
```

If the server does not trust the required certificate chain yet, install the
provider-recommended certificates. `LLM_TLS_VERIFY=0` exists for diagnostics,
but it should not be a production default.

## Yandex AI Studio

```env
LLM_PROVIDER=yandex
LLM_API_FORMAT=chat_completions
LLM_BASE_URL=https://ai.api.cloud.yandex.net/v1
LLM_AUTH_TYPE=bearer
YANDEX_API_KEY=<api-key>
YANDEX_FOLDER_ID=<folder-id>
LLM_MODEL_DEFAULT=yandexgpt-5.1/latest
LLM_VISION_MODEL_DEFAULT=gemma-3-27b-it
LLM_MODELS=yandexgpt-5.1/latest,yandexgpt-5-pro/latest,yandexgpt-5-lite/latest,aliceai-llm/latest,gemma-3-27b-it
```

`YANDEX_FOLDER_ID` is optional in FeedTools config. If it is set, FeedTools sends
`gpt://<folder-id>/yandexgpt-5.1/latest` internally while the dropdown keeps the
short `yandexgpt-5.1/latest` label.

Yandex AI Studio requires image URLs in chat requests to be inline
`data:image/...;base64,...` values. FeedTools converts remote image URLs to
base64 automatically for this provider before sending vision requests.
YandexGPT text models do not process images in the OpenAI-compatible API, so
FeedTools uses `LLM_VISION_MODEL_DEFAULT` for vision operations.

## MWS GPT Model Hub

```env
LLM_PROVIDER=mws
LLM_API_FORMAT=chat_completions
LLM_BASE_URL=https://gpt.mwsapis.ru/projects/<project-name>/openai/v1
LLM_AUTH_TYPE=bearer
LLM_API_KEY=<service-account-api-key>
LLM_MODEL_DEFAULT=qwen3-235b-instruct
LLM_MODELS=qwen3-235b-instruct,qwen3-32b,glm-4.6-357b,kimi-k2-instruct
```

## Cloud.ru Evolution Foundation Models

```env
LLM_PROVIDER=cloudru
LLM_API_FORMAT=chat_completions
LLM_BASE_URL=https://foundation-models.api.cloud.ru/v1
LLM_AUTH_TYPE=bearer
LLM_API_KEY=<api-key>
LLM_MODEL_DEFAULT=Qwen/Qwen3-235B-A22B-Instruct-2507
LLM_MODELS=Qwen/Qwen3-235B-A22B-Instruct-2507,zai-org/GLM-4.7,MiniMaxAI/MiniMax-M2
```
