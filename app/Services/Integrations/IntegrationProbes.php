<?php

namespace App\Services\Integrations;

use App\Services\Ai\LlmClient;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Lightweight HTTP probes for Settings → Verify (Phase 4.6).
 * Chat/completions fallback uses {@see LlmClient::verifyChatCompletionsPing} with registry disabled so the probe uses only the supplied base URL.
 */
final class IntegrationProbes
{
    /**
     * Validates Bearer credentials against an OpenAI-compatible base URL without calling paid image endpoints.
     * Tries GET /v1/models first, then POST /v1/chat/completions with a 1-token completion limit.
     *
     * @return array{ok: true, probe: string}|array{ok: false, status?: int, error?: string, body?: string}
     */
    public static function openAiCompatibleAuth(
        string $baseUrl,
        string $apiKey,
        float $timeout = 20.0,
    ): array {
        $baseUrl = rtrim($baseUrl, '/');
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'Empty API key'];
        }
        if ($baseUrl === '') {
            return [
                'ok' => false,
                'error' => 'Missing API base URL. For image providers like nano-banana, set TUTOR_IMAGE_BASE_URL or TUTOR_NANO_BANANA_IMAGE_BASE_URL (OpenAI-compatible root, usually with /v1).',
            ];
        }

        try {
            $models = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeout)
                ->connectTimeout(min(10.0, $timeout))
                ->get($baseUrl.'/models', ['limit' => 1]);

            if ($models->successful()) {
                return ['ok' => true, 'probe' => 'GET /v1/models'];
            }
        } catch (Throwable) {
            // Fall through to chat/completions probe.
        }

        $model = (string) config('tutor.default_chat.model', 'gpt-4o-mini');

        $chat = LlmClient::verifyChatCompletionsPing($baseUrl, $apiKey, $model, $timeout, false);
        if ($chat['ok']) {
            return ['ok' => true, 'probe' => 'POST /v1/chat/completions'];
        }

        if (isset($chat['body']) && is_string($chat['body'])) {
            return [
                'ok' => false,
                'status' => $chat['status'] ?? null,
                'body' => self::truncateBody($chat['body']),
            ];
        }

        return [
            'ok' => false,
            'status' => $chat['status'] ?? null,
            'error' => $chat['error'] ?? 'Chat probe failed',
        ];
    }

    /**
     * MiniMax: POST /v1/video_generation with an empty body expects parameter errors (2013) when the key is valid.
     *
     * @return array{ok: true, probe: string}|array{ok: false, status?: int, error?: string, baseRespCode?: int}
     */
    public static function minimaxVideoAuth(
        string $baseUrl,
        string $apiKey,
        float $timeout = 20.0,
    ): array {
        $baseUrl = rtrim($baseUrl, '/');
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'Empty API key'];
        }

        try {
            $res = Http::withToken($apiKey)
                ->withBody('{}', 'application/json')
                ->acceptJson()
                ->timeout($timeout)
                ->connectTimeout(min(10.0, $timeout))
                ->post($baseUrl.'/v1/video_generation');

            if ($res->status() === 401) {
                return ['ok' => false, 'status' => 401, 'error' => 'Unauthorized'];
            }

            $json = $res->json();
            if (! is_array($json)) {
                return [
                    'ok' => false,
                    'status' => $res->status(),
                    'error' => 'Non-JSON response',
                ];
            }

            $code = (int) data_get($json, 'base_resp.status_code', -1);
            if (in_array($code, [1004, 2049], true)) {
                return ['ok' => false, 'baseRespCode' => $code, 'error' => 'Invalid API key (MiniMax)'];
            }

            if ($code === 1008) {
                return ['ok' => false, 'baseRespCode' => $code, 'error' => 'MiniMax account balance insufficient'];
            }

            if ($code === 1026) {
                return ['ok' => false, 'baseRespCode' => $code, 'error' => 'MiniMax rejected request (content or policy)'];
            }

            if ($code === 2013 || $code === 0) {
                return ['ok' => true, 'probe' => 'POST /v1/video_generation (parameter check)'];
            }

            if ($res->successful() && $code === -1) {
                return ['ok' => true, 'probe' => 'POST /v1/video_generation'];
            }

            return [
                'ok' => false,
                'status' => $res->status(),
                'baseRespCode' => $code !== -1 ? $code : null,
                'error' => (string) data_get($json, 'base_resp.status_msg', 'Unexpected MiniMax response'),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private static function truncateBody(string $body, int $max = 500): string
    {
        if (strlen($body) <= $max) {
            return $body;
        }

        return substr($body, 0, $max).'…';
    }
}
