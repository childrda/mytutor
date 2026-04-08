<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * OpenAI-compatible chat completion helper (streaming and non-streaming).
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
        $url = rtrim($baseUrl, '/').'/chat/completions';
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

        $response = Http::withToken($apiKey, 'Bearer')
            ->acceptJson()
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
}
