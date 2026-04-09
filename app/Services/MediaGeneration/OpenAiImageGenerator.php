<?php

namespace App\Services\MediaGeneration;

use App\Services\Ai\LlmExchangeLogger;
use App\Support\ApiJson;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * OpenAI-compatible POST /v1/images/generations (Phase 4.2).
 *
 * Request JSON is intentionally only model, prompt, n, size (no response_format). Queue workers and
 * Octane keep PHP in memory — after deploying changes here run `php artisan queue:restart` (or
 * `php artisan horizon:terminate` for Horizon) so image jobs pick up the new code.
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
        $postBody = self::imagesGenerationsPayload($model, $prompt, $size);

        $logger = app(LlmExchangeLogger::class);
        $ctx = LlmExchangeLogger::mergeContext([]);
        $maxAttempts = max(1, min(5, (int) config('tutor.image_generation.http_max_attempts', 2)));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $logCid = (string) Str::ulid();
            $logger->record(
                'sent',
                $logCid,
                $ctx['user_id'],
                'image_generation',
                $postBody,
                '/v1/images/generations',
                null,
                $maxAttempts > 1 ? ['attempt' => $attempt, 'maxAttempts' => $maxAttempts] : [],
                'image',
            );

            try {
                $response = Http::asJson()
                    ->withToken($apiKey)
                    ->acceptJson()
                    ->timeout($timeout)
                    ->connectTimeout(min(30.0, $timeout))
                    ->post($url, $postBody);
            } catch (Throwable $e) {
                $logger->record(
                    'received',
                    $logCid,
                    $ctx['user_id'],
                    'image_generation',
                    [
                        'error' => $e->getMessage(),
                        'exception' => $e::class,
                    ],
                    '/v1/images/generations',
                    null,
                    array_merge(
                        ['transport_error' => true],
                        $maxAttempts > 1 ? ['attempt' => $attempt, 'maxAttempts' => $maxAttempts] : [],
                    ),
                    'image',
                );
                if ($attempt < $maxAttempts && self::isRetryableTransportError($e)) {
                    usleep((int) (400_000 * $attempt));

                    continue;
                }
                throw new ImageGenerationException(
                    'Image provider request failed: '.$e->getMessage(),
                    ApiJson::UPSTREAM_ERROR,
                    502,
                );
            }

            $rawBody = $response->body();
            $json = $response->json();
            $json = is_array($json) ? $json : [];
            $receivedPayload = $json !== []
                ? self::redactImageResponseForLog($json)
                : self::diagnosticsForNonJsonImageResponse($response, $rawBody);
            $logger->record(
                'received',
                $logCid,
                $ctx['user_id'],
                'image_generation',
                $receivedPayload,
                '/v1/images/generations',
                $response->status(),
                $maxAttempts > 1 ? ['attempt' => $attempt, 'maxAttempts' => $maxAttempts] : [],
                'image',
            );

            if ($response->status() === 401) {
                throw new ImageGenerationException('Invalid or missing API key', ApiJson::MISSING_API_KEY, 401);
            }

            if ($response->successful()) {
                return $this->decodeSuccessfulImageResponse($json);
            }

            if ($attempt < $maxAttempts && self::isRetryableHttpStatus($response->status())) {
                usleep((int) (400_000 * $attempt));

                continue;
            }

            if ($json === []) {
                throw new ImageGenerationException(
                    self::describeUnusableImageResponseBody($response, $rawBody),
                    ApiJson::UPSTREAM_ERROR,
                    502,
                );
            }
            $this->throwFromErrorBody($response->status(), $json);
        }

        throw new ImageGenerationException(
            'Image provider request failed after retries',
            ApiJson::UPSTREAM_ERROR,
            502,
        );
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{binary: string, mime: string, revisedPrompt: ?string}
     *
     * @throws ImageGenerationException
     */
    private function decodeSuccessfulImageResponse(array $json): array
    {
        $revised = data_get($json, 'data.0.revised_prompt');
        $revisedPrompt = is_string($revised) ? $revised : null;

        $b64 = data_get($json, 'data.0.b64_json');
        if (is_string($b64) && $b64 !== '') {
            $binary = base64_decode($b64, true);
            if ($binary === false || $binary === '') {
                throw new ImageGenerationException(
                    'Invalid base64 image data from provider',
                    ApiJson::GENERATION_FAILED,
                    502,
                );
            }

            return [
                'binary' => $binary,
                'mime' => 'image/png',
                'revisedPrompt' => $revisedPrompt,
            ];
        }

        $url = data_get($json, 'data.0.url');
        if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
            $fetchTimeout = min(120.0, max(15.0, (float) config('tutor.image_generation.timeout', 120)));
            try {
                $img = Http::timeout($fetchTimeout)
                    ->connectTimeout(min(30.0, $fetchTimeout))
                    ->accept('*/*')
                    ->get($url);
            } catch (Throwable $e) {
                throw new ImageGenerationException(
                    'Image provider returned a URL but download failed: '.$e->getMessage(),
                    ApiJson::GENERATION_FAILED,
                    502,
                );
            }
            if (! $img->successful()) {
                throw new ImageGenerationException(
                    'Image provider returned a URL but download failed (HTTP '.$img->status().').',
                    ApiJson::GENERATION_FAILED,
                    502,
                );
            }
            $binary = $img->body();
            if ($binary === '') {
                throw new ImageGenerationException(
                    'Image provider URL returned an empty body',
                    ApiJson::GENERATION_FAILED,
                    502,
                );
            }
            $mime = (string) $img->header('Content-Type');
            if ($mime === '' || ! str_starts_with($mime, 'image/')) {
                $mime = 'image/png';
            }

            return [
                'binary' => $binary,
                'mime' => $mime,
                'revisedPrompt' => $revisedPrompt,
            ];
        }

        $keys = array_keys($json);
        $hint = $keys === [] ? 'empty JSON object' : 'keys: '.implode(', ', array_slice($keys, 0, 8));
        throw new ImageGenerationException(
            'Image provider returned no image data (expected data[0].b64_json or data[0].url). Got '.$hint.'.',
            ApiJson::GENERATION_FAILED,
            502,
        );
    }

    /**
     * Strict request body: do not add keys (e.g. response_format) that strict gateways reject.
     *
     * @return array{model: string, prompt: string, n: int, size: string}
     */
    private static function imagesGenerationsPayload(string $model, string $prompt, string $size): array
    {
        return [
            'model' => $model,
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
        ];
    }

    private static function isRetryableHttpStatus(int $status): bool
    {
        return in_array($status, [408, 429, 502, 503, 504], true);
    }

    private static function isRetryableTransportError(Throwable $e): bool
    {
        return $e instanceof ConnectionException;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    /**
     * When the upstream is not OpenAI-shaped JSON (wrong URL, Imagen/Vertex, HTML error page, empty body).
     *
     * @return array<string, mixed>
     */
    private static function diagnosticsForNonJsonImageResponse(
        Response $response,
        string $rawBody,
    ): array {
        return [
            '_parse_note' => 'Body was empty or not a JSON object; OpenAI Images returns { data: [{ b64_json }] }.',
            '_http_status' => $response->status(),
            '_response_ok' => $response->successful(),
            '_content_type' => (string) $response->header('Content-Type'),
            '_raw_body_length' => strlen($rawBody),
            '_raw_body_preview' => mb_substr($rawBody, 0, 4000),
        ];
    }

    /**
     * Human-readable hint for UI / logs when the upstream body is not OpenAI-shaped JSON.
     */
    private static function describeUnusableImageResponseBody(Response $response, string $rawBody): string
    {
        $status = $response->status();
        $ct = (string) $response->header('Content-Type');
        $len = strlen($rawBody);
        $preview = trim(mb_substr(preg_replace('/\s+/u', ' ', $rawBody), 0, 280));

        $parts = [
            'Image provider returned a non-JSON or empty response body',
            "(HTTP {$status}".($ct !== '' ? ", Content-Type: {$ct}" : '').", {$len} bytes).",
        ];
        if ($preview !== '') {
            $parts[] = 'Body preview: '.$preview;
        } else {
            $parts[] = 'Body was empty — often a network block, proxy, wrong base URL, or TLS interception.';
        }
        $parts[] = 'Confirm TUTOR_IMAGE_BASE_URL is https://api.openai.com/v1, config is not stale (php artisan config:clear), and the server can reach api.openai.com.';

        return implode(' ', $parts);
    }

    private static function redactImageResponseForLog(array $json): array
    {
        if (! isset($json['data']) || ! is_array($json['data'])) {
            return $json;
        }
        $out = $json;
        foreach ($json['data'] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['b64_json']) && is_string($row['b64_json'])) {
                $b64 = $row['b64_json'];
                $len = strlen($b64);
                $out['data'][$i]['b64_json'] = '<<redacted: '.$len.' base64 chars>>';
                $out['data'][$i]['_decoded_bytes_approx'] = (int) floor($len * 0.75);
            }
            if (isset($row['url']) && is_string($row['url'])) {
                $out['data'][$i]['url'] = '<<redacted url>>';
            }
        }

        return $out;
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
