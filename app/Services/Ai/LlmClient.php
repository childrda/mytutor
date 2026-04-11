<?php

namespace App\Services\Ai;

use App\Services\Ai\LlmWire\LlmInputWire;
use App\Services\Ai\LlmWire\RegistryLlmMessageNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * OpenAI-compatible chat completion helper (streaming and non-streaming).
 *
 * Non-streaming {@see chat} / {@see chatWithMessages} use {@see config/models.json} when
 * {@see config('tutor.active.llm')} is set and the entry is executable for {@see LlmInputWire}
 * (OpenAI chat/completions, Anthropic /v1/messages, or Gemini generateContent — see {@see RegistryLlmMessageNormalizer}).
 * {@see StatelessChatStreamer} and {@see streamChat} pass {@see openAiRegistryChatEndpointAndHeaders} `$forEventStream = true` (SSE Accept).
 * Non-stream pool callers use `$forEventStream = false` (JSON Accept).
 * {@see verifyChatCompletionsPing} powers legacy / integration probes (uses active LLM entry when set).
 * Settings catalog row tests use {@see verifyLlmCatalogRowProbe} so the probed models.json row defines wire + URL.
 * {@see IntegrationProbes} passes {@code useActiveLlmRegistry: false} so image/TTS/ASR credential checks hit the given base URL only.
 * Otherwise behavior is unchanged (legacy HTTP).
 */
final class LlmClient
{
    /**
     * @return array<string, int>
     */
    public static function completionLimitPayload(?int $maxTokens): array
    {
        if ($maxTokens === null || $maxTokens < 1) {
            return [];
        }

        $param = (string) config('tutor.llm_completion_limit_param', 'max_completion_tokens');

        return $param === 'max_completion_tokens'
            ? ['max_completion_tokens' => $maxTokens]
            : ['max_tokens' => $maxTokens];
    }

    /**
     * Model id for the active registry {@code llm} row (classroom chat + lesson generation payloads).
     *
     * - Literal {@code request_format.model} (no braces): returned as-is.
     * - {@code {model|default}}: prefers {@code config('tutor.default_chat.model')} (from {@code TUTOR_DEFAULT_LLM_MODEL}), then the template default.
     * - {@code {model}} with no default: {@code null} so callers use {@code config('tutor.default_chat.model')}.
     * - No active LLM or registry errors: {@code null}.
     * - When {@code request_format.model} is absent or empty, uses {@see ModelRegistryTemplate::defaultModelIdFromEntry} (e.g. endpoint-only {@code {model|…}}).
     */
    public static function resolveActiveRegistryModel(): ?string
    {
        try {
            $registry = app(ModelRegistry::class);
            if (! $registry->hasActive('llm')) {
                return null;
            }
            $entry = $registry->activeEntry('llm');
        } catch (Throwable) {
            return null;
        }

        $rf = $entry['request_format'] ?? null;
        if (is_array($rf) && isset($rf['model']) && is_string($rf['model'])) {
            $modelField = trim($rf['model']);
            if ($modelField !== '') {
                if (! str_contains($modelField, '{')) {
                    return $modelField;
                }
                if (preg_match('/^\{model\|([^}]+)\}$/', $modelField, $m)) {
                    $fromConfig = trim((string) config('tutor.default_chat.model', ''));
                    if ($fromConfig !== '') {
                        return $fromConfig;
                    }
                    $templateDefault = trim($m[1]);

                    return $templateDefault !== '' ? $templateDefault : null;
                }
                if ($modelField === '{model}') {
                    return null;
                }
            }
        }

        $fromRow = ModelRegistryTemplate::defaultModelIdFromEntry($entry);
        if (is_string($fromRow) && $fromRow !== '' && ! str_contains($fromRow, '{')) {
            return $fromRow;
        }

        return null;
    }

    /**
     * Optional log context (merged with {@see LlmLogContext} and Auth):
     * user_id?, source?, correlation_id?
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array{user_id?: int|null, source?: string, correlation_id?: string|null}  $logContext
     */
    public static function chat(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        ?float $temperature = 0.3,
        ?int $maxTokens = 2048,
        array $logContext = [],
    ): string {
        $viaRegistry = self::executeChatCompletionsViaRegistryIfEligible(
            $baseUrl,
            $apiKey,
            $model,
            $messages,
            $temperature,
            $maxTokens,
            $logContext,
            120.0,
            false,
        );
        if ($viaRegistry !== null) {
            return $viaRegistry;
        }

        $url = rtrim($baseUrl, '/').'/chat/completions';
        $requestBody = array_merge([
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
        ], self::completionLimitPayload($maxTokens));

        $logger = app(LlmExchangeLogger::class);
        $ctx = LlmExchangeLogger::mergeContext($logContext);
        $cid = $ctx['correlation_id'] ?? (string) Str::ulid();

        if ($logger->enabled()) {
            $logger->record('sent', $cid, $ctx['user_id'], $ctx['source'], $requestBody, '/chat/completions', null, [
                'model' => $model,
            ]);
        }

        $res = Http::withToken($apiKey, 'Bearer')
            ->acceptJson()
            ->timeout(120)
            ->post($url, $requestBody);

        if (! $res->successful()) {
            if ($logger->enabled()) {
                $logger->record('received', $cid, $ctx['user_id'], $ctx['source'], [
                    'body' => $res->body(),
                ], '/chat/completions', $res->status(), ['error' => true]);
            }
            throw new RuntimeException('LLM request failed: '.$res->body());
        }

        $data = $res->json();
        if ($logger->enabled()) {
            $logger->record('received', $cid, $ctx['user_id'], $ctx['source'], is_array($data) ? $data : ['_non_json' => $res->body()], '/chat/completions', $res->status(), []);
        }

        $content = data_get($data, 'choices.0.message.content');
        if (! is_string($content)) {
            throw new RuntimeException('LLM returned no text content.');
        }

        return $content;
    }

    /**
     * Chat with arbitrary message shapes (e.g. multimodal user content for vision models).
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  array{user_id?: int|null, source?: string, correlation_id?: string|null}  $logContext
     */
    public static function chatWithMessages(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        ?float $temperature = 0.3,
        ?int $maxTokens = 2048,
        array $logContext = [],
    ): string {
        $viaRegistry = self::executeChatCompletionsViaRegistryIfEligible(
            $baseUrl,
            $apiKey,
            $model,
            $messages,
            $temperature,
            $maxTokens,
            $logContext,
            300.0,
            true,
        );
        if ($viaRegistry !== null) {
            return $viaRegistry;
        }

        $url = rtrim($baseUrl, '/').'/chat/completions';
        $requestBody = array_merge([
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
        ], self::completionLimitPayload($maxTokens));

        $logger = app(LlmExchangeLogger::class);
        $ctx = LlmExchangeLogger::mergeContext($logContext);
        $cid = $ctx['correlation_id'] ?? (string) Str::ulid();

        if ($logger->enabled()) {
            $logger->record('sent', $cid, $ctx['user_id'], $ctx['source'], $requestBody, '/chat/completions', null, [
                'model' => $model,
                'multimodal' => true,
            ]);
        }

        $res = Http::withToken($apiKey, 'Bearer')
            ->acceptJson()
            ->timeout(300)
            ->post($url, $requestBody);

        if (! $res->successful()) {
            if ($logger->enabled()) {
                $logger->record('received', $cid, $ctx['user_id'], $ctx['source'], [
                    'body' => $res->body(),
                ], '/chat/completions', $res->status(), ['error' => true, 'multimodal' => true]);
            }
            throw new RuntimeException('LLM request failed: '.$res->body());
        }

        $data = $res->json();
        if ($logger->enabled()) {
            $logger->record('received', $cid, $ctx['user_id'], $ctx['source'], is_array($data) ? $data : ['_non_json' => $res->body()], '/chat/completions', $res->status(), [
                'multimodal' => true,
            ]);
        }

        $content = data_get($data, 'choices.0.message.content');
        if (! is_string($content)) {
            throw new RuntimeException('LLM returned no text content.');
        }

        return $content;
    }

    /**
     * Stream chat completion deltas (OpenAI-style SSE). Yields assistant content fragments only.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array{user_id?: int|null, source?: string, correlation_id?: string|null}  $logContext
     * @return \Generator<int, string, mixed, void>
     */
    public static function streamChat(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        ?float $temperature = 0.3,
        ?int $maxTokens = 2048,
        array $logContext = [],
    ): \Generator {
        $requestBody = array_merge([
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'stream' => true,
        ], self::completionLimitPayload($maxTokens));

        $logger = app(LlmExchangeLogger::class);
        $ctx = LlmExchangeLogger::mergeContext($logContext);
        $cid = $ctx['correlation_id'] ?? (string) Str::ulid();

        if ($logger->enabled()) {
            $logger->record('sent', $cid, $ctx['user_id'], $ctx['source'], $requestBody, '/chat/completions', null, [
                'model' => $model,
                'stream' => true,
            ]);
        }

        $resolved = self::openAiRegistryChatEndpointAndHeaders($baseUrl, $apiKey, $model, true);
        $url = $resolved['url'] ?? rtrim($baseUrl, '/').'/chat/completions';
        $pending = $resolved !== null
            ? Http::withHeaders($resolved['headers'])
            : Http::withToken($apiKey, 'Bearer')->acceptJson();

        $response = $pending
            ->timeout(300)
            ->withOptions([
                'stream' => true,
                'read_timeout' => 300,
            ])
            ->post($url, $requestBody);

        if ($response->failed()) {
            if ($logger->enabled()) {
                $logger->record('received', $cid, $ctx['user_id'], $ctx['source'], [
                    'body' => $response->body(),
                ], '/chat/completions', $response->status(), ['stream' => true, 'error' => true]);
            }
            throw new RuntimeException('LLM stream request failed: '.$response->body());
        }

        $stream = $response->toPsrResponse()->getBody();
        $carry = '';
        $accumulated = '';
        $status = $response->status();

        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(4096);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $carry .= $chunk;

                while (($pos = strpos($carry, "\n")) !== false) {
                    $line = substr($carry, 0, $pos);
                    $carry = substr($carry, $pos + 1);
                    $line = rtrim($line, "\r");
                    if ($line === '' || str_starts_with($line, ':')) {
                        continue;
                    }
                    if (! str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $payload = trim(substr($line, strlen('data:')));
                    if ($payload === '' || $payload === '[DONE]') {
                        if ($payload === '[DONE]') {
                            return;
                        }

                        continue;
                    }

                    try {
                        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        continue;
                    }

                    if (! is_array($data)) {
                        continue;
                    }

                    $delta = $data['choices'][0]['delta']['content'] ?? null;
                    if (is_string($delta) && $delta !== '') {
                        $accumulated .= $delta;
                        yield $delta;
                    }
                }
            }
        } finally {
            if ($logger->enabled()) {
                $logger->record('received', $cid, $ctx['user_id'], $ctx['source'], [
                    'streamed_assistant_text' => $accumulated,
                    'note' => 'Reconstructed from stream text deltas only (not raw SSE bytes).',
                ], '/chat/completions', $status, ['stream' => true]);
            }
        }
    }

    /**
     * Settings / integration probes: minimal POST (1-token cap).
     * When {@code $useActiveLlmRegistry} is true and {@see config('tutor.active.llm')} selects an entry executable for
     * {@see LlmInputWire}, uses {@see ModelRegistryHttpExecutor} like {@see chat}; otherwise legacy Bearer POST to
     * `{baseUrl}/chat/completions`. Integration image/TTS/ASR probes pass {@code false} so the active LLM entry is never mixed in.
     *
     * @return array{ok: true}|array{ok: false, status?: int, body?: string, error?: string}
     */
    public static function verifyChatCompletionsPing(
        string $baseUrl,
        string $apiKey,
        string $model,
        float $timeout = 30.0,
        bool $useActiveLlmRegistry = true,
    ): array {
        $messages = [['role' => 'user', 'content' => 'ping']];
        $pack = $useActiveLlmRegistry
            ? self::maybeRegistryLlmChatPackage(
                $baseUrl,
                $apiKey,
                $model,
                $messages,
                1.0,
                1,
                $timeout,
            )
            : null;

        if ($pack !== null) {
            try {
                app(ModelRegistryHttpExecutor::class)->executeWithResolvedJsonBody(
                    $pack['entry'],
                    $pack['body'],
                    $pack['vars'],
                );
            } catch (ModelRegistryException $e) {
                $status = null;
                if (preg_match('/\((\d+)\)/', $e->getMessage(), $m)) {
                    $status = (int) $m[1];
                }

                return ['ok' => false, 'status' => $status, 'body' => $e->getMessage()];
            }

            return ['ok' => true];
        }

        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '') {
            return [
                'ok' => false,
                'error' => 'Missing base URL for chat/completions probe (set TUTOR_IMAGE_BASE_URL / TUTOR_NANO_BANANA_IMAGE_BASE_URL or pass baseUrl).',
            ];
        }

        $url = $baseUrl.'/chat/completions';

        try {
            $res = Http::withToken($apiKey, 'Bearer')
                ->acceptJson()
                ->timeout($timeout)
                ->connectTimeout(min(10.0, $timeout))
                ->post($url, array_merge([
                    'model' => $model,
                    'messages' => $messages,
                ], self::completionLimitPayload(1)));

            if (! $res->successful()) {
                return ['ok' => false, 'status' => $res->status(), 'body' => $res->body()];
            }

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Log {@see LlmExchangeLogger} meta.model from the serialized request body when present so it matches
     * the payload column (registry expansion can differ from the caller's {@code $model} argument).
     *
     * @param  array<string, mixed>  $requestBody
     */
    private static function metaModelForLlmLog(array $requestBody, string $callerModel): string
    {
        $m = $requestBody['model'] ?? null;

        return is_string($m) && $m !== '' ? $m : $callerModel;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private static function executeChatCompletionsViaRegistryIfEligible(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        ?float $temperature,
        ?int $maxTokens,
        array $logContext,
        float $timeout,
        bool $multimodalMeta,
    ): ?string {
        $pack = self::maybeRegistryLlmChatPackage(
            $baseUrl,
            $apiKey,
            $model,
            $messages,
            $temperature,
            $maxTokens,
            $timeout,
        );
        if ($pack === null) {
            return null;
        }

        $logger = app(LlmExchangeLogger::class);
        $ctx = LlmExchangeLogger::mergeContext($logContext);
        $cid = $ctx['correlation_id'] ?? (string) Str::ulid();

        $requestBody = $pack['body'];
        $logEndpoint = $pack['log_endpoint'];
        // Meta must reflect the wire body (expanded registry template), not the caller's $model hint.
        $meta = ['model' => self::metaModelForLlmLog(is_array($requestBody) ? $requestBody : [], $model)];
        if ($multimodalMeta) {
            $meta['multimodal'] = true;
        }
        if ($logger->enabled()) {
            $logger->record('sent', $cid, $ctx['user_id'], $ctx['source'], $requestBody, $logEndpoint, null, $meta);
        }

        try {
            $result = app(ModelRegistryHttpExecutor::class)->executeWithResolvedJsonBody($pack['entry'], $requestBody, $pack['vars']);
        } catch (ModelRegistryException $e) {
            if ($logger->enabled()) {
                $logger->record('received', $cid, $ctx['user_id'], $ctx['source'], [
                    'error' => $e->getMessage(),
                ], $logEndpoint, null, array_merge($meta, ['error' => true]));
            }
            throw new RuntimeException('LLM request failed: '.$e->getMessage());
        }

        $data = $result->json;
        if ($logger->enabled()) {
            $recvMeta = $multimodalMeta ? ['multimodal' => true] : [];
            $logger->record(
                'received',
                $cid,
                $ctx['user_id'],
                $ctx['source'],
                is_array($data) ? $data : ['_non_json' => $result->rawBody],
                $logEndpoint,
                $result->status,
                $recvMeta,
            );
        }

        $content = $result->extracted;
        if (! is_string($content) && is_array($data)) {
            $content = data_get($data, 'choices.0.message.content')
                ?? data_get($data, 'content.0.text')
                ?? data_get($data, 'candidates.0.content.parts.0.text');
        }
        if (! is_string($content)) {
            throw new RuntimeException('LLM returned no text content.');
        }

        return $content;
    }

    /**
     * Build a registry-shaped chat probe package for a specific models.json LLM row (not necessarily the active LLM).
     *
     * @param  list<array<string, mixed>>  $messages
     * @return array{entry: array<string, mixed>, body: array<string, mixed>, vars: array<string, mixed>, wire: LlmInputWire, log_endpoint: string}|null
     */
    private static function registryLlmChatPackageFromEntry(
        array $entry,
        string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        ?float $temperature,
        ?int $maxTokens,
        float $timeout,
    ): ?array {
        $wire = LlmInputWire::fromEntry($entry);
        $resolved = RegistryTemplateVarsResolver::merge('llm', $entry, [
            'api_key' => $apiKey,
            'base_url' => rtrim($baseUrl, '/'),
            'model' => $model,
        ]);
        $resolvedBaseUrl = (string) ($resolved['base_url'] ?? '');
        $resolvedApiKey = (string) ($resolved['api_key'] ?? '');
        if (! self::registryLlmEntryExecutableForWire($entry, $resolvedBaseUrl, $resolvedApiKey, $model, $wire)) {
            return null;
        }

        $extraVars = RegistryLlmMessageNormalizer::templateVars($wire, $messages);
        if ($extraVars === null) {
            return null;
        }

        $vars = array_merge($resolved, [
            'temperature' => $temperature,
            'timeout' => $timeout,
            'max_output_tokens' => max(1, $maxTokens ?? 2048),
        ], $extraVars);

        try {
            $expanded = ModelRegistryTemplate::expandRequestFormat($entry['request_format'], $vars);
        } catch (ModelRegistryException) {
            return null;
        }

        if (isset($expanded['system']) && $expanded['system'] === '') {
            unset($expanded['system']);
        }
        if (isset($expanded['systemInstruction']['parts'][0]['text']) && $expanded['systemInstruction']['parts'][0]['text'] === '') {
            unset($expanded['systemInstruction']);
        }

        $body = match ($wire) {
            LlmInputWire::OpenAiChatCompletions => self::mergeOpenAiCompletionLimitsIntoBody($expanded, $maxTokens),
            LlmInputWire::AnthropicMessages => self::mergeAnthropicCompletionLimitsIntoBody($expanded, $maxTokens),
            LlmInputWire::GoogleGenerateContent => self::mergeGoogleCompletionLimitsIntoBody($expanded, $maxTokens),
        };

        $logEndpoint = match ($wire) {
            LlmInputWire::OpenAiChatCompletions => '/chat/completions',
            LlmInputWire::AnthropicMessages => '/v1/messages',
            LlmInputWire::GoogleGenerateContent => '/generateContent',
        };

        return [
            'entry' => $entry,
            'body' => $body,
            'vars' => $vars,
            'wire' => $wire,
            'log_endpoint' => $logEndpoint,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array{entry: array<string, mixed>, body: array<string, mixed>, vars: array<string, mixed>, wire: LlmInputWire, log_endpoint: string}|null
     */
    private static function maybeRegistryLlmChatPackage(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        ?float $temperature,
        ?int $maxTokens,
        float $timeout,
    ): ?array {
        $registry = app(ModelRegistry::class);
        if (! $registry->hasActive('llm')) {
            return null;
        }

        return self::registryLlmChatPackageFromEntry(
            $registry->activeEntry('llm'),
            $baseUrl,
            $apiKey,
            $model,
            $messages,
            $temperature,
            $maxTokens,
            $timeout,
        );
    }

    /**
     * Settings catalog: HTTP probe for one models.json LLM row. Uses that row's wire and endpoint (not the globally active LLM).
     *
     * @param  array<string, mixed>  $entry
     * @return array{ok: true}|array{ok: false, status?: int, body?: string, error?: string, message?: string}
     */
    public static function verifyLlmCatalogRowProbe(
        array $entry,
        string $apiKey,
        string $baseUrl,
        string $model,
        float $timeout = 25.0,
    ): array {
        $messages = [['role' => 'user', 'content' => 'ping']];
        $baseUrlTrim = rtrim($baseUrl, '/');
        $pack = self::registryLlmChatPackageFromEntry(
            $entry,
            $baseUrlTrim,
            $apiKey,
            $model,
            $messages,
            1.0,
            1,
            $timeout,
        );

        if ($pack === null) {
            if (trim($apiKey) === '') {
                return ['ok' => false, 'message' => 'No API key resolved (set apiKey in request or matching provider env_key).'];
            }
            if ($baseUrlTrim === '') {
                return ['ok' => false, 'error' => 'Missing base URL for probe.'];
            }

            return self::verifyChatCompletionsPing($baseUrlTrim, $apiKey, $model, $timeout, false);
        }

        try {
            app(ModelRegistryHttpExecutor::class)->executeWithResolvedJsonBody(
                $pack['entry'],
                $pack['body'],
                $pack['vars'],
            );
        } catch (ModelRegistryException $e) {
            $status = null;
            if (preg_match('/\((\d+)\)/', $e->getMessage(), $m)) {
                $status = (int) $m[1];
            }

            return ['ok' => false, 'status' => $status, 'body' => $e->getMessage()];
        }

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $expanded
     * @return array<string, mixed>
     */
    private static function mergeOpenAiCompletionLimitsIntoBody(array $expanded, ?int $maxTokens): array
    {
        $param = (string) config('tutor.llm_completion_limit_param', 'max_completion_tokens');
        if ($param === 'max_completion_tokens') {
            unset($expanded['max_tokens']);
        } else {
            unset($expanded['max_completion_tokens']);
        }

        return array_merge($expanded, self::completionLimitPayload($maxTokens));
    }

    /**
     * @param  array<string, mixed>  $expanded
     * @return array<string, mixed>
     */
    private static function mergeAnthropicCompletionLimitsIntoBody(array $expanded, ?int $maxTokens): array
    {
        unset($expanded['max_completion_tokens']);
        $lim = self::completionLimitPayload($maxTokens);
        if (isset($lim['max_tokens'])) {
            $expanded['max_tokens'] = $lim['max_tokens'];
        } elseif (isset($lim['max_completion_tokens'])) {
            $expanded['max_tokens'] = $lim['max_completion_tokens'];
        }

        return $expanded;
    }

    /**
     * @param  array<string, mixed>  $expanded
     * @return array<string, mixed>
     */
    private static function mergeGoogleCompletionLimitsIntoBody(array $expanded, ?int $maxTokens): array
    {
        $lim = self::completionLimitPayload($maxTokens);
        $n = $lim['max_tokens'] ?? $lim['max_completion_tokens'] ?? null;
        if ($n !== null && isset($expanded['generationConfig']) && is_array($expanded['generationConfig'])) {
            $expanded['generationConfig']['maxOutputTokens'] = min(32768, max(1, (int) $n));
        }

        return $expanded;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function registryLlmEntryExecutableForWire(
        array $entry,
        string $baseUrl,
        string $apiKey,
        string $model,
        LlmInputWire $wire,
    ): bool {
        if (! isset($entry['request_format']) || ! is_array($entry['request_format'])) {
            return false;
        }

        $endpoint = isset($entry['endpoint']) ? trim((string) $entry['endpoint']) : '';
        if ($endpoint === '') {
            return false;
        }

        try {
            $expandedUrl = ModelRegistryTemplate::expandUrl($endpoint, [
                'base_url' => rtrim($baseUrl, '/'),
                'api_key' => $apiKey,
                'model' => $model,
            ]);
        } catch (ModelRegistryException) {
            return false;
        }

        $responsePath = isset($entry['response_path']) ? (string) $entry['response_path'] : '';
        $pathKey = ModelRegistryTemplate::responsePathToDataGetKey($responsePath);

        return match ($wire) {
            LlmInputWire::OpenAiChatCompletions => str_contains($expandedUrl, 'chat/completions')
                && $pathKey === 'choices.0.message.content',
            LlmInputWire::AnthropicMessages => str_contains($expandedUrl, '/messages')
                && $pathKey === 'content.0.text',
            LlmInputWire::GoogleGenerateContent => str_contains($expandedUrl, 'generateContent')
                && str_starts_with($pathKey, 'candidates'),
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function registryLlmEntrySupportsResolvedOpenAiChatBody(
        array $entry,
        string $baseUrl,
        string $apiKey,
        string $model,
    ): bool {
        return self::registryLlmEntryExecutableForWire(
            $entry,
            $baseUrl,
            $apiKey,
            $model,
            LlmInputWire::OpenAiChatCompletions,
        );
    }

    /**
     * Resolved chat/completions URL and auth headers when the active LLM registry entry is OpenAI-compatible.
     * Use `$forEventStream = true` for SSE (streaming); `false` for JSON non-stream responses (e.g. parallel pool posts).
     * {@see StatelessChatStreamer} still builds the JSON body (`model`, `stream`, `messages`, tools) unchanged for parity with the legacy path.
     *
     * @return array{url: string, headers: array<string, string>}|null
     */
    public static function openAiRegistryChatEndpointAndHeaders(
        string $baseUrl,
        string $apiKey,
        string $model,
        bool $forEventStream = false,
    ): ?array {
        $registry = app(ModelRegistry::class);
        if (! $registry->hasActive('llm')) {
            return null;
        }

        $entry = $registry->activeEntry('llm');
        $vars = RegistryTemplateVarsResolver::merge('llm', $entry, [
            'api_key' => $apiKey,
            'base_url' => rtrim($baseUrl, '/'),
            'model' => $model,
        ]);
        if (! self::registryLlmEntrySupportsResolvedOpenAiChatBody(
            $entry,
            (string) ($vars['base_url'] ?? ''),
            (string) ($vars['api_key'] ?? ''),
            $model,
        )) {
            return null;
        }

        $endpoint = trim((string) ($entry['endpoint'] ?? ''));

        try {
            $url = ModelRegistryTemplate::expandUrl($endpoint, $vars);
        } catch (ModelRegistryException) {
            return null;
        }

        $headers = app(ModelRegistryHttpExecutor::class)->authHeadersForEntry($entry, $vars);
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = $forEventStream ? 'text/event-stream' : 'application/json';

        return ['url' => $url, 'headers' => $headers];
    }
}
