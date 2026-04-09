<?php

namespace App\Services\Ai;

use JsonException;

/**
 * Loads and serves {@see config/model_registry.json} (Phase 2). Resolver HTTP client is a later phase.
 *
 * Registered as a singleton — one parse per PHP worker lifecycle.
 */
final class ModelRegistry
{
    /** @var list<string> */
    public const CAPABILITIES = ['llm', 'image', 'tts', 'asr', 'pdf', 'video', 'web_search'];

    /** @var array<string, mixed> */
    private readonly array $data;

    public function __construct(?string $path = null)
    {
        $path ??= config_path('model_registry.json');
        $this->data = self::loadAndValidate($path);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function schemaVersion(): int
    {
        $v = $this->data['schema_version'] ?? null;

        return is_int($v) ? $v : 0;
    }

    /**
     * @return array<string, mixed> Provider entry (may be stub with only _note)
     */
    public function get(string $capability, string $key): array
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            throw ModelRegistryException::unknownCapability($capability, implode(', ', self::CAPABILITIES));
        }

        $section = $this->data[$capability] ?? null;
        if (! is_array($section)) {
            throw ModelRegistryException::unknownCapability($capability, implode(', ', self::CAPABILITIES));
        }

        if ($key === '' || str_starts_with($key, '_')) {
            throw ModelRegistryException::unknownProvider($capability, $key);
        }

        if (! array_key_exists($key, $section) || ! is_array($section[$key])) {
            throw ModelRegistryException::unknownProvider($capability, $key);
        }

        return $section[$key];
    }

    public function has(string $capability, string $key): bool
    {
        if (! in_array($capability, self::CAPABILITIES, true) || $key === '' || str_starts_with($key, '_')) {
            return false;
        }
        $section = $this->data[$capability] ?? null;

        return is_array($section) && isset($section[$key]) && is_array($section[$key]);
    }

    /**
     * Public provider keys under a capability (excludes entries whose key starts with '_').
     *
     * @return list<string>
     */
    public function providerKeys(string $capability): array
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            throw ModelRegistryException::unknownCapability($capability, implode(', ', self::CAPABILITIES));
        }
        $section = $this->data[$capability] ?? null;
        if (! is_array($section)) {
            return [];
        }
        $out = [];
        foreach (array_keys($section) as $k) {
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
            throw ModelRegistryException::fileMissing($path);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw ModelRegistryException::fileMissing($path);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ModelRegistryException::invalidJson($e->getMessage());
        }

        if (! is_array($data)) {
            throw ModelRegistryException::invalidSchema('root must be a JSON object');
        }

        if (($data['schema_version'] ?? null) !== 1) {
            throw ModelRegistryException::invalidSchema('schema_version must be 1');
        }

        foreach (self::CAPABILITIES as $cap) {
            if (! array_key_exists($cap, $data)) {
                throw ModelRegistryException::invalidSchema("missing capability section: {$cap}");
            }
            if (! is_array($data[$cap])) {
                throw ModelRegistryException::invalidSchema("capability {$cap} must be an object");
            }
        }

        return $data;
    }
}
