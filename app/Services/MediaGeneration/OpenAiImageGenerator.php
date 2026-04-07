<?php

namespace App\Services\MediaGeneration;

use App\Support\ApiJson;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OpenAI-compatible POST /v1/images/generations (Phase 4.2).
 */
final class OpenAiImageGenerator
{
    private const array DALLE3_SIZES = ['1024x1024', '1792x1024', '1024x1792'];

    private const array DALLE2_SIZES = ['256x256', '512x512', '1024x1024'];

    /**
     * @return array{binary: string, mime: string, revisedPrompt: ?string}
     *
     * @throws ImageGenerationException
     */
    public function generate(
        string $prompt,
        ?string $size = null,
        ?string $model = null,
        ?string $overrideApiKey = null,
    ): array {
        $prompt = trim($prompt);
        $maxChars = max(100, (int) config('tutor.image_generation.max_prompt_chars', 4000));
        if ($prompt === '') {
            throw new ImageGenerationException('Prompt is required', ApiJson::MISSING_REQUIRED_FIELD, 400);
        }
        if (strlen($prompt) > $maxChars) {
            throw new ImageGenerationException(
                'Prompt exceeds maximum length ('.$maxChars.' characters)',
                ApiJson::INVALID_REQUEST,
                400,
            );
        }

        $apiKey = $overrideApiKey !== null && $overrideApiKey !== ''
            ? $overrideApiKey
            : (string) config('tutor.image_generation.api_key');
        if ($apiKey === '') {
            throw new ImageGenerationException('API key is required', ApiJson::MISSING_API_KEY, 401);
        }

        $baseUrl = rtrim((string) config('tutor.image_generation.base_url'), '/');
        $model = $model !== null && $model !== '' ? $model : (string) config('tutor.image_generation.model', 'dall-e-3');
        $size = $size !== null && $size !== '' ? $size : (string) config('tutor.image_generation.default_size', '1024x1024');
        $this->assertValidSizeForModel($model, $size);

        $timeout = (float) config('tutor.image_generation.timeout', 120);
        $url = $baseUrl.'/images/generations';

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeout)
                ->connectTimeout(min(30.0, $timeout))
                ->post($url, [
                    'model' => $model,
                    'prompt' => $prompt,
                    'n' => 1,
                    'size' => $size,
                    'response_format' => 'b64_json',
                ]);
        } catch (Throwable $e) {
            throw new ImageGenerationException(
                'Image provider request failed: '.$e->getMessage(),
                ApiJson::UPSTREAM_ERROR,
                502,
            );
        }

        if ($response->status() === 401) {
            throw new ImageGenerationException('Invalid or missing API key', ApiJson::MISSING_API_KEY, 401);
        }

        if (! $response->successful()) {
            $decoded = $response->json();
            if (! is_array($decoded)) {
                throw new ImageGenerationException(
                    'Image provider returned an invalid response',
                    ApiJson::UPSTREAM_ERROR,
                    502,
                );
            }
            $this->throwFromErrorBody($response->status(), $decoded);
        }

        $json = $response->json();
        $b64 = data_get($json, 'data.0.b64_json');
        if (! is_string($b64) || $b64 === '') {
            throw new ImageGenerationException(
                'Image provider returned no image data',
                ApiJson::GENERATION_FAILED,
                502,
            );
        }

        $binary = base64_decode($b64, true);
        if ($binary === false || $binary === '') {
            throw new ImageGenerationException(
                'Invalid base64 image data from provider',
                ApiJson::GENERATION_FAILED,
                502,
            );
        }

        $revised = data_get($json, 'data.0.revised_prompt');
        $revisedPrompt = is_string($revised) ? $revised : null;

        return [
            'binary' => $binary,
            'mime' => 'image/png',
            'revisedPrompt' => $revisedPrompt,
        ];
    }

    private function assertValidSizeForModel(string $model, string $size): void
    {
        $allowed = str_starts_with($model, 'dall-e-2') || $model === 'dall-e-2'
            ? self::DALLE2_SIZES
            : self::DALLE3_SIZES;

        if (! in_array($size, $allowed, true)) {
            throw new ImageGenerationException(
                'Invalid size for model '.$model.'. Allowed: '.implode(', ', $allowed),
                ApiJson::INVALID_REQUEST,
                400,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $json
     *
     * @throws ImageGenerationException
     */
    private function throwFromErrorBody(int $status, array $json): void
    {
        $type = (string) data_get($json, 'error.type');
        $code = (string) data_get($json, 'error.code');
        $msg = (string) data_get($json, 'error.message', 'Image generation failed');

        if ($code === 'content_policy_violation'
            || str_contains(strtolower($type.$msg), 'content_policy')
            || str_contains(strtolower($msg), 'safety system')) {
            throw new ImageGenerationException($msg, ApiJson::CONTENT_SENSITIVE, 422);
        }

        if ($status >= 500) {
            throw new ImageGenerationException($msg, ApiJson::UPSTREAM_ERROR, 502);
        }

        throw new ImageGenerationException($msg, ApiJson::GENERATION_FAILED, 422);
    }
}
