<?php

namespace App\Services\Ai;

use JsonException;

/**
 * Loads {@see config/models.json} (Phase 2 — model list) and merges auth defaults from {@see ProviderRegistry}.
 * Phase 3: {@see RegistryTemplateVarsResolver} fills empty {@code api_key} / {@code base_url} at HTTP time from the provider catalog.
 * Active selection via {@see activeKey()} / {@see activeEntry()} (Phase 4).
 *
 * Registered as a singleton — one parse per PHP worker lifecycle.
 */
final class ModelRegistry
{
    /** @var list<string> */
    public const CAPABILITIES = ['llm', 'image', 'tts', 'asr', 'pdf', 'video', 'web_search'];

    /** @var array<string, mixed> */
    private readonly array $data;

    public function __construct(
        ?string $modelsPath = null,
        ?ProviderRegistry $providerRegistry = null,
    ) {
        $modelsPath ??= config_path('models.json');
        $registry = $providerRegistry ?? self::resolveProviderRegistry();
        $this->data = self::loadAndValidate($modelsPath, $registry);
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
     * @return array<string, mixed> Model entry (may be stub with only _note). Includes id, display_name, enabled when present.
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

        $entry = $section[$key];
        if (($entry['enabled'] ?? true) === false) {
            throw ModelRegistryException::unknownProvider($capability, $key);
        }

        return $entry;
    }

    public function has(string $capability, string $key): bool
    {
        if (! in_array($capability, self::CAPABILITIES, true) || $key === '' || str_starts_with($key, '_')) {
            return false;
        }
        $section = $this->data[$capability] ?? null;
        if (! is_array($section) || ! isset($section[$key]) || ! is_array($section[$key])) {
            return false;
        }
        if (($section[$key]['enabled'] ?? true) === false) {
            return false;
        }

        return true;
    }

    /**
     * Public model ids under a capability (enabled only).
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
            $entry = $section[$k];
            if (! is_array($entry) || (($entry['enabled'] ?? true) === false)) {
                continue;
            }
            $out[] = $k;
        }
        sort($out);

        return $out;
    }

    public function hasActive(string $capability): bool
    {
        return $this->activeKey($capability) !== null;
    }

    public function activeKey(string $capability): ?string
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            throw ModelRegistryException::unknownCapability($capability, implode(', ', self::CAPABILITIES));
        }

        return app(TutorActiveRegistrySelection::class)->resolve($capability);
    }

    /**
     * Model entry for the configured active key (env/config tutor.active.* overrides DB row from Settings).
     *
     * @return array<string, mixed>
     */
    public function activeEntry(string $capability): array
    {
        $key = $this->activeKey($capability);
        if ($key === null) {
            throw ModelRegistryException::activeSelectionNotConfigured($capability);
        }

        return $this->get($capability, $key);
    }

    private static function resolveProviderRegistry(): ProviderRegistry
    {
        if (function_exists('app') && app()->bound(ProviderRegistry::class)) {
            return app(ProviderRegistry::class);
        }

        return new ProviderRegistry;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadAndValidate(string $path, ProviderRegistry $providerRegistry): array
    {
        if (! is_readable($path)) {
            throw ModelRegistryException::fileMissing($path);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw ModelRegistryException::fileMissing($path);
        }

        try {
            $rawData = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ModelRegistryException::invalidJson($e->getMessage());
        }

        if (! is_array($rawData)) {
            throw ModelRegistryException::invalidSchema('root must be a JSON object');
        }

        if (($rawData['schema_version'] ?? null) !== 1) {
            throw ModelRegistryException::invalidSchema('schema_version must be 1');
        }

        $out = [
            'schema_version' => 1,
        ];
        if (isset($rawData['_meta']) && is_array($rawData['_meta'])) {
            $out['_meta'] = $rawData['_meta'];
        }

        foreach (self::CAPABILITIES as $cap) {
            if (! array_key_exists($cap, $rawData)) {
                throw ModelRegistryException::invalidSchema("missing capability section: {$cap}");
            }
            $list = $rawData[$cap];
            if (! is_array($list)) {
                throw ModelRegistryException::invalidSchema("capability {$cap} must be a JSON array of model objects");
            }

            $section = [];
            foreach ($list as $i => $row) {
                if (! is_array($row)) {
                    throw ModelRegistryException::invalidSchema("capability {$cap}[{$i}] must be an object");
                }
                $id = $row['id'] ?? null;
                if (! is_string($id) || $id === '' || str_starts_with($id, '_')) {
                    throw ModelRegistryException::invalidSchema("capability {$cap}[{$i}].id must be a non-empty string");
                }
                if (isset($section[$id])) {
                    throw ModelRegistryException::invalidSchema("duplicate model id \"{$id}\" under capability {$cap}");
                }
                $providerId = $row['provider'] ?? null;
                if (! is_string($providerId) || $providerId === '') {
                    throw ModelRegistryException::invalidSchema("capability {$cap}.{$id} missing string \"provider\"");
                }
                if (! $providerRegistry->has($providerId)) {
                    throw ModelRegistryException::invalidSchema(
                        "capability {$cap}.{$id} references unknown provider catalog id \"{$providerId}\"",
                    );
                }

                if (isset($row['enabled']) && ! is_bool($row['enabled'])) {
                    throw ModelRegistryException::invalidSchema("capability {$cap}.{$id}.enabled must be boolean when set");
                }

                if (! isset($row['display_name']) || ! is_string($row['display_name']) || trim($row['display_name']) === '') {
                    throw ModelRegistryException::invalidSchema("capability {$cap}.{$id} missing non-empty display_name");
                }

                if (array_key_exists('base_url', $row)) {
                    if (! is_string($row['base_url'])) {
                        throw ModelRegistryException::invalidSchema("capability {$cap}.{$id}.base_url must be a string when set");
                    }
                    $bu = trim($row['base_url']);
                    if ($bu !== '' && ! preg_match('#\Ahttps?://#i', $bu)) {
                        throw ModelRegistryException::invalidSchema(
                            "capability {$cap}.{$id}.base_url must be empty or an http(s) URL",
                        );
                    }
                }

                $p = $providerRegistry->get($providerId);
                $authLayer = [
                    'auth_header' => $p['auth_header'],
                    'auth_scheme' => $p['auth_scheme'],
                ];
                $merged = array_merge($authLayer, $row);
                $section[$id] = $merged;
            }

            $out[$cap] = $section;
        }

        return $out;
    }
}
