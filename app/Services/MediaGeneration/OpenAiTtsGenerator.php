<?php

namespace App\Services\MediaGeneration;

use App\Support\ApiJson;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OpenAI-compatible POST /v1/audio/speech (Phase 4.3).
 */
final class OpenAiTtsGenerator
{
    private const array VOICES = [
        'alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'onyx', 'nova', 'sage', 'shimmer',
    ];

    private const array MODELS = ['tts-1', 'tts-1-hd'];

    private const array FORMATS = ['mp3', 'opus', 'aac', 'flac', 'wav'];

    /**
     * @return array{binary: string, mime: string, format: string}
     *
     * @throws TtsGenerationException
     */
    public function generate(
        string $text,
        ?string $voice = null,
        ?string $model = null,
        ?string $format = null,
        ?string $overrideApiKey = null,
    ): array {
        $text = trim($text);
        $maxChars = max(1, min(4096, (int) config('tutor.tts_generation.max_input_chars', 4096)));
        if ($text === '') {
            throw new TtsGenerationException('Text input is required', ApiJson::MISSING_REQUIRED_FIELD, 400);
        }
        if (strlen($text) > $maxChars) {
            throw new TtsGenerationException(
                'Text exceeds maximum length ('.$maxChars.' characters)',
                ApiJson::INVALID_REQUEST,
                400,
            );
        }

        $apiKey = $overrideApiKey !== null && $overrideApiKey !== ''
            ? $overrideApiKey
            : (string) config('tutor.tts_generation.api_key');
        if ($apiKey === '') {
            throw new TtsGenerationException('API key is required', ApiJson::MISSING_API_KEY, 401);
        }

        $baseUrl = rtrim((string) config('tutor.tts_generation.base_url'), '/');
        $model = $model !== null && $model !== '' ? $model : (string) config('tutor.tts_generation.model', 'tts-1');
        if (! in_array($model, self::MODELS, true)) {
            throw new TtsGenerationException(
                'Invalid model. Allowed: '.implode(', ', self::MODELS),
                ApiJson::INVALID_REQUEST,
                400,
            );
        }

        $voice = $voice !== null && $voice !== '' ? strtolower(trim($voice)) : (string) config('tutor.tts_generation.voice', 'alloy');
        if (! in_array($voice, self::VOICES, true)) {
            throw new TtsGenerationException(
                'Invalid voice. Allowed: '.implode(', ', self::VOICES),
                ApiJson::INVALID_REQUEST,
                400,
            );
        }

        $format = $format !== null && $format !== '' ? strtolower(trim($format)) : (string) config('tutor.tts_generation.format', 'mp3');
        if (! in_array($format, self::FORMATS, true)) {
            throw new TtsGenerationException(
                'Invalid format. Allowed: '.implode(', ', self::FORMATS),
                ApiJson::INVALID_REQUEST,
                400,
            );
        }

        $timeout = (float) config('tutor.tts_generation.timeout', 120);
        $url = $baseUrl.'/audio/speech';

        try {
            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->connectTimeout(min(30.0, $timeout))
                ->post($url, [
                    'model' => $model,
                    'input' => $text,
                    'voice' => $voice,
                    'response_format' => $format,
                ]);
        } catch (Throwable $e) {
            throw new TtsGenerationException(
                'TTS provider request failed: '.$e->getMessage(),
                ApiJson::UPSTREAM_ERROR,
                502,
            );
        }

        if ($response->status() === 401) {
            throw new TtsGenerationException('Invalid or missing API key', ApiJson::MISSING_API_KEY, 401);
        }

        if (! $response->successful()) {
            $decoded = $response->json();
            if (is_array($decoded)) {
                $this->throwFromErrorBody($response->status(), $decoded);
            }
            throw new TtsGenerationException(
                'TTS provider returned an error',
                ApiJson::UPSTREAM_ERROR,
                502,
            );
        }

        $binary = $response->body();
        if ($binary === '') {
            throw new TtsGenerationException(
                'TTS provider returned empty audio',
                ApiJson::GENERATION_FAILED,
                502,
            );
        }

        $mime = match ($format) {
            'mp3' => 'audio/mpeg',
            'opus' => 'audio/opus',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav',
            default => 'application/octet-stream',
        };

        return [
            'binary' => $binary,
            'mime' => $mime,
            'format' => $format,
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     *
     * @throws TtsGenerationException
     */
    private function throwFromErrorBody(int $status, array $json): void
    {
        $code = (string) data_get($json, 'error.code');
        $msg = (string) data_get($json, 'error.message', 'Text-to-speech failed');

        if ($code === 'content_policy_violation'
            || str_contains(strtolower($msg), 'content_policy')
            || str_contains(strtolower($msg), 'safety')) {
            throw new TtsGenerationException($msg, ApiJson::CONTENT_SENSITIVE, 422);
        }

        if ($status >= 500) {
            throw new TtsGenerationException($msg, ApiJson::UPSTREAM_ERROR, 502);
        }

        throw new TtsGenerationException($msg, ApiJson::GENERATION_FAILED, 422);
    }
}
