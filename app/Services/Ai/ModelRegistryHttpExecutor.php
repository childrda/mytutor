<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Data-driven HTTP POST from a single merged model registry entry (Phase 3).
 * Supports `request_encoding` json (default) and multipart for ASR-style file uploads (Phase 7).
 * No provider-specific branching — behavior comes from the entry arrays only.
 */
final class ModelRegistryHttpExecutor
{
    public function __construct(
        private readonly float $defaultTimeoutSeconds = 120.0,
    ) {}

    /**
     * @param  array<string, mixed>  $entry  One merged model object from {@see ModelRegistry} (e.g. llm.openai)
     * @param  array<string, mixed>  $vars  Template variables: api_key, base_url, messages, model, prompt, etc.
     */
    public function execute(array $entry, array $vars = []): ModelRegistryHttpResult
    {
        if (! isset($entry['request_format']) || ! is_array($entry['request_format'])) {
            throw ModelRegistryException::invalidProviderEntry('request_format is missing or not an object (stub provider?).');
        }
        if (! isset($entry['endpoint']) || ! is_string($entry['endpoint']) || trim($entry['endpoint']) === '') {
            throw ModelRegistryException::invalidProviderEntry('endpoint is missing or empty.');
        }

        $encoding = $entry['request_encoding'] ?? 'json';
        $url = ModelRegistryTemplate::expandUrl($entry['endpoint'], $vars);
        $body = ModelRegistryTemplate::expandRequestFormat($entry['request_format'], $vars);

        $timeout = isset($vars['timeout']) && is_numeric($vars['timeout'])
            ? (float) $vars['timeout']
            : $this->defaultTimeoutSeconds;

        $headers = $this->authHeaders($entry, $vars);

        try {
            $response = match ($encoding) {
                'json' => $this->postJson($url, $body, $headers, $timeout),
                'multipart' => $this->postMultipart($url, $body, $headers, $timeout),
                default => throw ModelRegistryException::requestEncodingNotSupported((string) $encoding),
            };
        } catch (ModelRegistryException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ModelRegistryException::httpFailed(0, $e->getMessage());
        }

        return $this->buildResult($response, $entry);
    }

    /**
     * POST a fully built JSON body while still using the entry’s endpoint template, auth headers, and response_path.
     * Used by {@see LlmClient} to merge {@see LlmClient::completionLimitPayload()} without duplicating registry templates.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $jsonBody
     * @param  array<string, mixed>  $vars  Endpoint/auth/timeout placeholders only
     */
    public function executeWithResolvedJsonBody(array $entry, array $jsonBody, array $vars = []): ModelRegistryHttpResult
    {
        if (! isset($entry['endpoint']) || ! is_string($entry['endpoint']) || trim($entry['endpoint']) === '') {
            throw ModelRegistryException::invalidProviderEntry('endpoint is missing or empty.');
        }

        $url = ModelRegistryTemplate::expandUrl($entry['endpoint'], $vars);
        $timeout = isset($vars['timeout']) && is_numeric($vars['timeout'])
            ? (float) $vars['timeout']
            : $this->defaultTimeoutSeconds;
        $headers = $this->authHeaders($entry, $vars);

        try {
            $response = $this->postJson($url, $jsonBody, $headers, $timeout);
        } catch (ModelRegistryException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ModelRegistryException::httpFailed(0, $e->getMessage());
        }

        return $this->buildResult($response, $entry);
    }

    /**
     * Auth headers from registry only (e.g. streaming clients that build their own JSON body).
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $vars
     * @return array<string, string>
     */
    public function authHeadersForEntry(array $entry, array $vars): array
    {
        return $this->authHeaders($entry, $vars);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $vars
     * @return array<string, string>
     */
    private function authHeaders(array $entry, array $vars): array
    {
        $headers = [];
        $authHeader = $entry['auth_header'] ?? null;
        $authScheme = $entry['auth_scheme'] ?? null;
        if (is_string($authHeader) && $authHeader !== '' && $authScheme !== null && $authScheme !== '') {
            $headers[$authHeader] = ModelRegistryTemplate::expandUrl((string) $authScheme, $vars);
        }

        $extra = $entry['request_headers'] ?? null;
        if (is_array($extra)) {
            foreach ($extra as $name => $value) {
                if (is_string($name) && $name !== '' && is_string($value) && $value !== '') {
                    $headers[$name] = $value;
                }
            }
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function postJson(string $url, array $body, array $headers, float $timeout): Response
    {
        return Http::withHeaders($headers)
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout(min(30.0, $timeout))
            ->post($url, $body);
    }

    /**
     * @param  array<string, mixed>  $body  Expanded request_format: file fields are attach payloads (path+filename or contents+filename)
     * @param  array<string, string>  $headers
     */
    private function postMultipart(string $url, array $body, array $headers, float $timeout): Response
    {
        $pending = Http::withHeaders($headers)
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout(min(30.0, $timeout));

        $form = [];
        foreach ($body as $name => $value) {
            if (! is_string($name) || $name === '') {
                throw ModelRegistryException::invalidProviderEntry('multipart request_format keys must be non-empty strings.');
            }
            $file = self::multipartFileDescriptor($value);
            if ($file !== null) {
                $pending = $pending->attach($name, $file['contents'], $file['filename']);

                continue;
            }
            if (is_array($value) || is_object($value)) {
                throw ModelRegistryException::invalidProviderEntry(
                    'multipart field "'.$name.'" must be a scalar or a file descriptor array (path+filename or contents+filename).',
                );
            }
            $form[$name] = self::multipartScalarField($value);
        }

        return $pending->post($url, $form);
    }

    /**
     * @return array{contents: string, filename: string}|null
     */
    private static function multipartFileDescriptor(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        if (isset($value['path'], $value['filename']) && is_string($value['path']) && is_string($value['filename'])) {
            $path = $value['path'];
            if ($path === '' || ! is_readable($path)) {
                throw ModelRegistryException::invalidProviderEntry('multipart file path missing or unreadable.');
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw ModelRegistryException::invalidProviderEntry('multipart file could not be read: '.$path);
            }
            if ($contents === '') {
                throw ModelRegistryException::invalidProviderEntry('multipart file is empty: '.$path);
            }

            return ['contents' => $contents, 'filename' => $value['filename']];
        }
        if (isset($value['contents'], $value['filename'])
            && is_string($value['filename'])) {
            $c = (string) $value['contents'];
            if ($c === '') {
                throw ModelRegistryException::invalidProviderEntry('multipart contents must not be empty.');
            }

            return ['contents' => $c, 'filename' => $value['filename']];
        }

        return null;
    }

    private static function multipartScalarField(mixed $value): string|int|float|bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return (string) $value;
    }

    private function buildResult(Response $response, array $entry): ModelRegistryHttpResult
    {
        $raw = $response->body();
        $json = $response->json();
        $json = is_array($json) ? $json : null;

        if (! $response->successful()) {
            $preview = mb_substr(preg_replace('/\s+/u', ' ', $raw), 0, 400);
            throw ModelRegistryException::httpFailed($response->status(), $preview !== '' ? $preview : '(empty body)');
        }

        $extracted = $this->extractPayload($entry, $json, $raw);

        return new ModelRegistryHttpResult(
            status: $response->status(),
            successful: true,
            headers: $response->headers(),
            json: $json,
            rawBody: $raw,
            extracted: $extracted,
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>|null  $json
     */
    private function extractPayload(array $entry, ?array $json, string $rawBody): mixed
    {
        $responseType = isset($entry['response_type']) ? (string) $entry['response_type'] : '';

        if ($responseType === 'binary') {
            return $rawBody;
        }

        if ($responseType === 'binary_base64') {
            $path = isset($entry['response_path']) ? (string) $entry['response_path'] : '';
            if ($path === '' || $json === null) {
                return null;
            }
            $key = ModelRegistryTemplate::responsePathToDataGetKey($path);
            $b64 = data_get($json, $key);
            if (! is_string($b64) || $b64 === '') {
                return null;
            }
            $decoded = base64_decode($b64, true);

            return $decoded === false ? null : $decoded;
        }

        $path = isset($entry['response_path']) ? (string) $entry['response_path'] : '';
        if ($path === '' || $json === null) {
            return null;
        }

        $key = ModelRegistryTemplate::responsePathToDataGetKey($path);

        return data_get($json, $key);
    }
}
