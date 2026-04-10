<?php

namespace App\Services\Ai;

use JsonException;

/**
 * Loads {@see config/providers.json} — static developer-managed catalog (Phase 2 provider layer).
 * Model endpoints and request shapes live in {@see config/models.json} ({@see ModelRegistry}).
 */
final class ProviderRegistry
{
    /** @var array<string, mixed> */
    private readonly array $data;

    public function __construct(?string $path = null)
    {
        $path ??= config_path('providers.json');
        $this->data = self::loadAndValidate($path);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data['providers'] ?? [];
    }

    public function schemaVersion(): int
    {
        $v = $this->data['schema_version'] ?? null;

        return is_int($v) ? $v : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        if ($id === '' || str_starts_with($id, '_')) {
            throw ProviderRegistryException::unknownProvider($id);
        }
        $providers = $this->all();
        if (! isset($providers[$id]) || ! is_array($providers[$id])) {
            throw ProviderRegistryException::unknownProvider($id);
        }

        return $providers[$id];
    }

    public function has(string $id): bool
    {
        if ($id === '' || str_starts_with($id, '_')) {
            return false;
        }
        $providers = $this->all();

        return isset($providers[$id]) && is_array($providers[$id]);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        $providers = $this->all();
        $out = [];
        foreach (array_keys($providers) as $k) {
            if (! is_string($k) || $k === '' || str_starts_with($k, '_')) {
                continue;
            }
            $out[] = $k;
        }
        sort($out);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadAndValidate(string $path): array
    {
        if (! is_readable($path)) {
            throw ProviderRegistryException::fileMissing($path);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw ProviderRegistryException::fileMissing($path);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ProviderRegistryException::invalidJson($e->getMessage());
        }

        if (! is_array($data)) {
            throw ProviderRegistryException::invalidSchema('root must be a JSON object');
        }

        if (($data['schema_version'] ?? null) !== 1) {
            throw ProviderRegistryException::invalidSchema('schema_version must be 1');
        }

        $providers = $data['providers'] ?? null;
        if (! is_array($providers)) {
            throw ProviderRegistryException::invalidSchema('providers must be an object');
        }

        $allowedCaps = ModelRegistry::CAPABILITIES;

        foreach ($providers as $id => $entry) {
            if (! is_string($id) || $id === '' || str_starts_with($id, '_')) {
                throw ProviderRegistryException::invalidSchema('invalid provider id key');
            }
            if (! is_array($entry)) {
                throw ProviderRegistryException::invalidSchema("providers.{$id} must be an object");
            }

            if (! isset($entry['name']) || ! is_string($entry['name']) || trim($entry['name']) === '') {
                throw ProviderRegistryException::invalidSchema("providers.{$id}.name must be a non-empty string");
            }

            $caps = $entry['capabilities'] ?? null;
            if (! is_array($caps) || $caps === []) {
                throw ProviderRegistryException::invalidSchema("providers.{$id}.capabilities must be a non-empty array");
            }
            foreach ($caps as $c) {
                if (! is_string($c) || ! in_array($c, $allowedCaps, true)) {
                    $allowed = implode(', ', $allowedCaps);
                    throw ProviderRegistryException::invalidSchema(
                        "providers.{$id}.capabilities contains invalid capability \"{$c}\". Allowed: {$allowed}.",
                    );
                }
            }

            if (! isset($entry['base_url']) || ! is_string($entry['base_url']) || trim($entry['base_url']) === '') {
                throw ProviderRegistryException::invalidSchema("providers.{$id}.base_url must be a non-empty string");
            }

            if (! array_key_exists('supports_custom_base_url', $entry) || ! is_bool($entry['supports_custom_base_url'])) {
                throw ProviderRegistryException::invalidSchema("providers.{$id}.supports_custom_base_url must be a boolean");
            }

            foreach (['auth_header', 'auth_scheme', 'env_key'] as $optionalScalar) {
                if (! array_key_exists($optionalScalar, $entry)) {
                    throw ProviderRegistryException::invalidSchema("providers.{$id} missing key: {$optionalScalar}");
                }
                $v = $entry[$optionalScalar];
                if ($v !== null && ! is_string($v)) {
                    throw ProviderRegistryException::invalidSchema("providers.{$id}.{$optionalScalar} must be string or null");
                }
            }

            $authHeader = $entry['auth_header'];
            $authScheme = $entry['auth_scheme'];
            if ($authHeader !== null && $authHeader !== '' && ($authScheme === null || $authScheme === '')) {
                throw ProviderRegistryException::invalidSchema(
                    "providers.{$id}: auth_scheme must be non-empty when auth_header is set",
                );
            }
            if (($authScheme !== null && $authScheme !== '') && ($authHeader === null || $authHeader === '')) {
                throw ProviderRegistryException::invalidSchema(
                    "providers.{$id}: auth_header must be non-empty when auth_scheme is set",
                );
            }

            if (isset($entry['request_templates']) && ! is_array($entry['request_templates'])) {
                throw ProviderRegistryException::invalidSchema("providers.{$id}.request_templates must be an object when present");
            }
        }

        return $data;
    }
}
