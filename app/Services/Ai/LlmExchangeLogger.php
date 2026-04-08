<?php

namespace App\Services\Ai;

use App\Models\LlmExchangeLog;
use Illuminate\Support\Facades\Auth;
use JsonException;
use Throwable;

/**
 * Persists outbound API bodies and inbound responses to llm_exchange_logs when enabled.
 * Chat uses {@see enabled}('llm'); image generation uses {@see enabled}('image').
 */
final class LlmExchangeLogger
{
    public function enabled(string $channel = 'llm'): bool
    {
        if ($channel === 'image') {
            return (bool) config('tutor.log_image_generation', false);
        }

        return (bool) config('tutor.log_llm', false);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  'llm'|'image'  $logChannel
     */
    public function record(
        string $direction,
        string $correlationId,
        ?int $userId,
        string $source,
        array|string $payload,
        string $endpoint = '/chat/completions',
        ?int $httpStatus = null,
        array $meta = [],
        string $logChannel = 'llm',
    ): void {
        if (! $this->enabled($logChannel)) {
            return;
        }

        if (! in_array($direction, ['sent', 'received'], true)) {
            return;
        }

        if ($correlationId === '') {
            return;
        }

        try {
            $metaOut = $meta;
            $encoded = $this->encodePayload($payload, $metaOut);
            $jobId = self::lessonGenerationJobIdFromContext();

            LlmExchangeLog::query()->create([
                'user_id' => $userId,
                'lesson_generation_job_id' => $jobId,
                'direction' => $direction,
                'source' => mb_substr($source, 0, 64),
                'correlation_id' => mb_substr($correlationId, 0, 26),
                'endpoint' => mb_substr($endpoint, 0, 128),
                'http_status' => $httpStatus,
                'payload' => $encoded,
                'meta' => $metaOut === [] ? null : $metaOut,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Never break LLM calls because logging failed
        }
    }

    /**
     * @param  array{user_id?: int|null, source?: string, correlation_id?: string|null}  $overrides
     * @return array{user_id: ?int, source: string, correlation_id: ?string}
     */
    public static function mergeContext(array $overrides): array
    {
        $base = LlmLogContext::current();
        $merged = array_merge(
            [
                'user_id' => null,
                'source' => 'llm_client',
                'correlation_id' => null,
            ],
            $base,
            $overrides,
        );

        $uid = self::normalizeUserId($merged['user_id'] ?? null);
        if ($uid === null && app()->bound('auth')) {
            $uid = self::normalizeUserId(Auth::id());
        }

        return [
            'user_id' => $uid,
            'source' => is_string($merged['source'] ?? null) && $merged['source'] !== ''
                ? $merged['source']
                : 'llm_client',
            'correlation_id' => isset($merged['correlation_id']) && is_string($merged['correlation_id']) && $merged['correlation_id'] !== ''
                ? $merged['correlation_id']
                : null,
        ];
    }

    private static function normalizeUserId(mixed $v): ?int
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && ctype_digit($v)) {
            return (int) $v;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta  Mutated when truncation occurs
     */
    private function encodePayload(array|string $payload, array &$meta): string
    {
        if (is_string($payload)) {
            $json = $payload;
        } else {
            try {
                $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            } catch (JsonException) {
                $json = '{"_error":"payload_not_json_encodable"}';
            }
        }

        $max = max(10_000, (int) config('tutor.log_llm_max_payload_bytes', 2_000_000));
        $len = strlen($json);
        if ($len > $max) {
            $meta['payload_truncated'] = true;
            $meta['payload_original_bytes'] = $len;
            $json = substr($json, 0, $max)."\n…[truncated by tutor.log_llm_max_payload_bytes]";
        }

        return $json;
    }

    private static function lessonGenerationJobIdFromContext(): ?string
    {
        $frame = LlmLogContext::current();
        $id = $frame['lesson_generation_job_id'] ?? null;
        if (! is_string($id)) {
            return null;
        }
        $id = trim($id);
        if ($id === '' || strlen($id) > 26) {
            return null;
        }

        return $id;
    }
}
