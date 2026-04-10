<?php

namespace App\Services\MediaGeneration;

use App\Services\Ai\ModelRegistry;
use App\Services\Ai\ModelRegistryException;
use App\Services\Ai\ModelRegistryHttpExecutor;
use App\Services\Ai\RegistryTemplateVarsResolver;
use App\Support\ApiJson;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OpenAI-compatible POST /v1/audio/speech (Phase 4.3).
 *
 * When {@see config('tutor.active.tts')} is set, uses {@see config/models.json} via
 * {@see ModelRegistryHttpExecutor} (Phase 6). Unset active TTS key (env, Settings DB, or both) for legacy path only.
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
        ?float $speed = null,
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

        $baseUrl = rtrim((string) config('tutor.tts_generation.base_url'), '/');
        $model = $model !== null && $model !== '' ? $model : (string) config('tutor.tts_generation.model', 'tts-1');
        $voiceInput = $voice !== null && $voice !== '' ? trim($voice) : (string) config('tutor.tts_generation.voice', 'alloy');
        $format = $format !== null && $format !== '' ? strtolower(trim($format)) : (string) config('tutor.tts_generation.format', 'mp3');

        if (app(ModelRegistry::class)->hasActive('tts')) {
            if (! in_array($format, self::FORMATS, true)) {
                throw new TtsGenerationException(
                    'Invalid format. Allowed: '.implode(', ', self::FORMATS),
                    ApiJson::INVALID_REQUEST,
                    400,
                );
            }
            $registry = app(ModelRegistry::class);
            $entry = $registry->activeEntry('tts');
            if (! isset($entry['request_format']) || ! is_array($entry['request_format'])) {
                $key = $registry->activeKey('tts') ?? '';

                throw new TtsGenerationException(
                    'Active TTS registry provider "'.$key.'" has no request_format (stub). '
                    .'Set TUTOR_ACTIVE_TTS, save an executable provider in Settings (when env is unset), or clear the active key for legacy generation.',
                    ApiJson::INVALID_REQUEST,
                    400,
                );
            }
            $voiceResolved = (($entry['provider'] ?? '') === 'openai') ? strtolower($voiceInput) : $voiceInput;
            if (($entry['provider'] ?? '') === 'openai') {
                if (! in_array($model, self::MODELS, true)) {
                    throw new TtsGenerationException(
                        'Invalid model. Allowed: '.implode(', ', self::MODELS),
                        ApiJson::INVALID_REQUEST,
                        400,
                    );
                }
                if (! in_array($voiceResolved, self::VOICES, true)) {
                    throw new TtsGenerationException(
                        'Invalid voice. Allowed: '.implode(', ', self::VOICES),
                        ApiJson::INVALID_REQUEST,
                        400,
                    );
                }
            }

            return $this->generateViaModelRegistry(
                $text,
                $model,
                $voiceResolved,
                $format,
                $apiKey,
                $baseUrl,
                $speed,
                $entry,
            );
        }

        if ($apiKey === '') {
            throw new TtsGenerationException('API key is required', ApiJson::MISSING_API_KEY, 401);
        }

        $voice = strtolower($voiceInput);

        if (! in_array($model, self::MODELS, true)) {
            throw new TtsGenerationException(
                'Invalid model. Allowed: '.implode(', ', self::MODELS),
                ApiJson::INVALID_REQUEST,
                400,
            );
        }

        if (! in_array($voice, self::VOICES, true)) {
            throw new TtsGenerationException(
                'Invalid voice. Allowed: '.implode(', ', self::VOICES),
                ApiJson::INVALID_REQUEST,
                400,
            );
        }

        if (! in_array($format, self::FORMATS, true)) {
            throw new TtsGenerationException(
                'Invalid format. Allowed: '.implode(', ', self::FORMATS),
                ApiJson::INVALID_REQUEST,
                400,
            );
        }

        $timeout = (float) config('tutor.tts_generation.timeout', 120);
        $url = $baseUrl.'/audio/speech';

        $payload = [
            'model' => $model,
            'input' => $text,
            'voice' => $voice,
            'response_format' => $format,
        ];
        if ($speed !== null) {
            $sp = round(min(4.0, max(0.25, $speed)), 2);
            if (abs($sp - 1.0) > 0.001) {
                $payload['speed'] = $sp;
            }
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->connectTimeout(min(30.0, $timeout))
                ->post($url, $payload);
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
     * @param  array<string, mixed>  $entry
     * @return array{binary: string, mime: string, format: string}
     *
     * @throws TtsGenerationException
     */
    private function generateViaModelRegistry(
        string $text,
        string $model,
        string $voice,
        string $format,
        string $apiKey,
        string $baseUrl,
        ?float $speed,
        array $entry,
    ): array {
        $timeout = (float) config('tutor.tts_generation.timeout', 120);
        $speedVar = 1.0;
        if ($speed !== null) {
            $speedVar = round(max(0.25, min(4.0, $speed)), 2);
        }

        $vars = RegistryTemplateVarsResolver::merge('tts', $entry, [
            'api_key' => $apiKey,
            'base_url' => rtrim($baseUrl, '/'),
            'text' => $text,
            'model' => $model,
            'voice' => $voice,
            'voice_id' => $voice,
            'response_format' => $format,
            'timeout' => $timeout,
            'speed' => $speedVar,
        ]);
        if (trim((string) ($vars['api_key'] ?? '')) === '') {
            throw new TtsGenerationException('API key is required', ApiJson::MISSING_API_KEY, 401);
        }

        $maxAttempts = max(1, min(5, (int) (config('tutor.tts_generation.http_max_attempts') ?? 2)));
        $executor = app(ModelRegistryHttpExecutor::class);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $result = $executor->execute($entry, $vars);
            } catch (ModelRegistryException $e) {
                $status = self::parseRegistryHttpFailureStatus($e);
                if ($attempt < $maxAttempts && self::isRetryableRegistryHttpStatus($status)) {
                    usleep((int) (400_000 * $attempt));

                    continue;
                }
                throw self::mapModelRegistryExceptionToTtsException($e, $status);
            }

            $binary = is_string($result->extracted) && $result->extracted !== ''
                ? $result->extracted
                : $result->rawBody;
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

        throw new TtsGenerationException(
            'TTS provider request failed after retries (model registry)',
            ApiJson::UPSTREAM_ERROR,
            502,
        );
    }

    private static function parseRegistryHttpFailureStatus(ModelRegistryException $e): ?int
    {
        if (preg_match('/Model registry HTTP request failed \\((\\d+)\\):/', $e->getMessage(), $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private static function isRetryableRegistryHttpStatus(?int $status): bool
    {
        if ($status === null || $status === 0) {
            return true;
        }

        return in_array($status, [408, 429, 502, 503, 504], true);
    }

    /**
     * @throws TtsGenerationException
     */
    private static function mapModelRegistryExceptionToTtsException(ModelRegistryException $e, ?int $httpStatus): TtsGenerationException
    {
        $msg = $e->getMessage();
        $lower = strtolower($msg);

        if (str_contains($lower, 'missing template variable')) {
            return new TtsGenerationException($msg, ApiJson::INVALID_REQUEST, 400);
        }
        if (str_contains($lower, 'invalid model registry provider entry')) {
            return new TtsGenerationException($msg, ApiJson::INVALID_REQUEST, 400);
        }
        if ($httpStatus === 401) {
            return new TtsGenerationException('Invalid or missing API key', ApiJson::MISSING_API_KEY, 401);
        }
        if (str_contains($lower, 'content_policy') || str_contains($lower, 'safety')) {
            return new TtsGenerationException($msg, ApiJson::CONTENT_SENSITIVE, 422);
        }
        if ($httpStatus !== null && $httpStatus >= 500) {
            return new TtsGenerationException($msg, ApiJson::UPSTREAM_ERROR, 502);
        }

        return new TtsGenerationException($msg, ApiJson::GENERATION_FAILED, 422);
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
