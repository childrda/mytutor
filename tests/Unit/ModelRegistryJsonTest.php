<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1 — validates config/model_registry.json structure and IntegrationCatalog id coverage.
 */
class ModelRegistryJsonTest extends TestCase
{
    private const array CAPABILITIES = ['llm', 'image', 'tts', 'asr', 'pdf', 'video', 'web_search'];

    /**
     * @return array<string, mixed>
     */
    private function loadRegistry(): array
    {
        $path = config_path('model_registry.json');
        $this->assertFileExists($path, 'config/model_registry.json must exist (Phase 1)');

        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->fail('model_registry.json is not valid JSON: '.$e->getMessage());
        }

        $this->assertIsArray($data);

        return $data;
    }

    #[Test]
    public function model_registry_json_is_valid_and_structured(): void
    {
        $data = $this->loadRegistry();

        $this->assertSame(1, $data['schema_version'] ?? null, 'schema_version must be 1');

        foreach (self::CAPABILITIES as $cap) {
            $this->assertArrayHasKey($cap, $data, "Missing capability section: {$cap}");
            $this->assertIsArray($data[$cap], "Capability {$cap} must be an object of providers");

            foreach ($data[$cap] as $providerKey => $entry) {
                $this->assertIsString($providerKey);
                $this->assertNotSame('', $providerKey, "Empty provider key under {$cap}");
                if (str_starts_with($providerKey, '_')) {
                    continue;
                }
                $this->assertIsArray($entry, "{$cap}.{$providerKey} must be an object");
                $this->assertProviderEntry($cap, $providerKey, $entry);
            }
        }
    }

    #[Test]
    public function model_registry_includes_all_integration_catalog_provider_ids(): void
    {
        $data = $this->loadRegistry();

        $maps = [
            'llm' => array_values(config('tutor.llm', [])),
            'tts' => array_values(config('tutor.tts', [])),
            'asr' => array_values(config('tutor.asr', [])),
            'pdf' => array_values(config('tutor.pdf', [])),
            'image' => array_values(config('tutor.image', [])),
            'video' => array_values(config('tutor.video', [])),
            'web_search' => array_values(config('tutor.web_search', [])),
        ];

        foreach ($maps as $cap => $ids) {
            foreach ($ids as $id) {
                $this->assertArrayHasKey(
                    $id,
                    $data[$cap],
                    "Registry [{$cap}] missing IntegrationCatalog id: {$id} (from config/tutor.php)",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function assertProviderEntry(string $cap, string $providerKey, array $entry): void
    {
        if (! isset($entry['request_format'])) {
            $this->assertArrayHasKey(
                '_note',
                $entry,
                "{$cap}.{$providerKey}: stub entries without request_format must include _note",
            );

            return;
        }

        $this->assertArrayHasKey('provider', $entry, "{$cap}.{$providerKey} missing provider");
        $this->assertIsString($entry['provider']);

        $this->assertArrayHasKey('endpoint', $entry, "{$cap}.{$providerKey} missing endpoint");
        $this->assertIsString($entry['endpoint']);
        $this->assertNotSame('', $entry['endpoint']);

        $this->assertIsArray($entry['request_format']);
        $this->assertNotEmpty($entry['request_format'], "{$cap}.{$providerKey} request_format empty");

        $hasPath = isset($entry['response_path']) && is_string($entry['response_path']) && $entry['response_path'] !== '';
        $hasType = isset($entry['response_type']) && is_string($entry['response_type']) && $entry['response_type'] !== '';
        $this->assertTrue(
            $hasPath || $hasType,
            "{$cap}.{$providerKey} must set response_path or response_type",
        );

        $this->assertArrayHasKey('auth_header', $entry, "{$cap}.{$providerKey} missing auth_header key");
        $this->assertArrayHasKey('auth_scheme', $entry, "{$cap}.{$providerKey} missing auth_scheme key");
    }
}
