<?php

namespace Tests\Unit;

use App\Services\Ai\LlmWire\LlmInputWire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Validates config/models.json source shape (array per capability) and IntegrationCatalog id coverage.
 */
class ModelRegistryJsonTest extends TestCase
{
    private const array CAPABILITIES = ['llm', 'image', 'tts', 'asr', 'pdf', 'video', 'web_search'];

    /**
     * @return array<string, mixed>
     */
    private function loadModelsJson(): array
    {
        $path = config_path('models.json');
        $this->assertFileExists($path, 'config/models.json must exist');

        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->fail('models.json is not valid JSON: '.$e->getMessage());
        }

        $this->assertIsArray($data);

        return $data;
    }

    #[Test]
    public function models_json_is_valid_and_structured(): void
    {
        $data = $this->loadModelsJson();

        $this->assertSame(1, $data['schema_version'] ?? null, 'schema_version must be 1');

        foreach (self::CAPABILITIES as $cap) {
            $this->assertArrayHasKey($cap, $data, "Missing capability section: {$cap}");
            $this->assertIsArray($data[$cap], "Capability {$cap} must be an array of model objects");

            foreach ($data[$cap] as $i => $entry) {
                $this->assertIsArray($entry, "{$cap}[{$i}] must be an object");
                $id = $entry['id'] ?? null;
                $this->assertIsString($id);
                $this->assertNotSame('', $id, "Empty id under {$cap}[{$i}]");
                if (str_starts_with($id, '_')) {
                    continue;
                }
                $this->assertModelRow($cap, $id, $entry);
            }
        }
    }

    #[Test]
    public function models_json_includes_all_integration_catalog_provider_ids(): void
    {
        $data = $this->loadModelsJson();

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
            $present = [];
            foreach ($data[$cap] as $row) {
                if (is_array($row) && isset($row['id']) && is_string($row['id'])) {
                    $present[$row['id']] = true;
                }
            }
            foreach ($ids as $id) {
                $this->assertArrayHasKey(
                    $id,
                    $present,
                    "models.json [{$cap}] missing IntegrationCatalog id: {$id} (from config/tutor.php)",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function assertModelRow(string $cap, string $modelId, array $entry): void
    {
        $this->assertArrayHasKey('provider', $entry, "{$cap}.{$modelId} missing provider");
        $this->assertIsString($entry['provider']);
        $this->assertArrayHasKey('display_name', $entry, "{$cap}.{$modelId} missing display_name");

        if (! isset($entry['request_format'])) {
            $this->assertArrayHasKey(
                '_note',
                $entry,
                "{$cap}.{$modelId}: stub entries without request_format must include _note",
            );

            return;
        }

        $this->assertArrayHasKey('endpoint', $entry, "{$cap}.{$modelId} missing endpoint");
        $this->assertIsString($entry['endpoint']);
        $this->assertNotSame('', $entry['endpoint']);

        $this->assertIsArray($entry['request_format']);
        $this->assertNotEmpty($entry['request_format'], "{$cap}.{$modelId} request_format empty");

        $hasPath = isset($entry['response_path']) && is_string($entry['response_path']) && $entry['response_path'] !== '';
        $hasType = isset($entry['response_type']) && is_string($entry['response_type']) && $entry['response_type'] !== '';
        $this->assertTrue(
            $hasPath || $hasType,
            "{$cap}.{$modelId} must set response_path or response_type",
        );

        $this->assertArrayHasKey('auth_header', $entry, "{$cap}.{$modelId} missing auth_header key");
        $this->assertArrayHasKey('auth_scheme', $entry, "{$cap}.{$modelId} missing auth_scheme key");

        if ($cap === 'llm' && isset($entry['llm_input_wire'])) {
            $this->assertIsString($entry['llm_input_wire']);
            $this->assertNotSame('', $entry['llm_input_wire']);
            $this->assertNotNull(
                LlmInputWire::tryFrom($entry['llm_input_wire']),
                "{$cap}.{$modelId}: unknown llm_input_wire: {$entry['llm_input_wire']}",
            );
        }

        if (isset($entry['request_headers'])) {
            $this->assertIsArray($entry['request_headers'], "{$cap}.{$modelId}: request_headers must be an object");
            foreach ($entry['request_headers'] as $hn => $hv) {
                $this->assertIsString($hn);
                $this->assertNotSame('', $hn);
                $this->assertIsString($hv, "{$cap}.{$modelId}: request_headers values must be strings");
            }
        }
    }
}
