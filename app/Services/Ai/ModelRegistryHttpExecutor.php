<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Data-driven HTTP POST from a single model_registry provider entry (Phase 3).
 * No provider-specific branching — behavior comes from the entry arrays only.
 */
final class ModelRegistryHttpExecutor
{
    public function __construct(
        private readonly float $defaultTimeoutSeconds = 120.0,
    ) {}

    /**
     * @param  array<string, mixed>  $entry  One provider object from model_registry.json (e.g. llm.openai)
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
        if ($encoding !== 'json') {
            throw ModelRegistryException::requestEncodingNotSupported((string) $encoding);
        }

        $url = ModelRegistryTemplate::expandUrl($entry['endpoint'], $vars);
        $body = ModelRegistryTemplate::expandRequestFormat($entry['request_format'], $vars);

        $timeout = isset($vars['timeout']) && is_numeric($vars['timeout'])
            ? (float) $vars['timeout']
            : $this->defaultTimeoutSeconds;

        $headers = [];
        $authHeader = $entry['auth_header'] ?? null;
        $authScheme = $entry['auth_scheme'] ?? null;
        if (is_string($authHeader) && $authHeader !== '' && $authScheme !== null && $authScheme !== '') {
            $headers[$authHeader] = ModelRegistryTemplate::expandUrl((string) $authScheme, $vars);
        }

        try {
            $response = Http::withHeaders($headers)
                ->acceptJson()
                ->timeout($timeout)
                ->connectTimeout(min(30.0, $timeout))
                ->post($url, $body);
        } catch (Throwable $e) {
            throw ModelRegistryException::httpFailed(0, $e->getMessage());
        }

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
