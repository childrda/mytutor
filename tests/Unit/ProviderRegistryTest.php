<?php

namespace Tests\Unit;

use App\Services\Ai\ModelRegistry;
use App\Services\Ai\ProviderRegistry;
use App\Services\Ai\ProviderRegistryException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProviderRegistryTest extends TestCase
{
    #[Test]
    public function providers_json_loads_and_matches_schema_version(): void
    {
        $reg = new ProviderRegistry(config_path('providers.json'));
        $this->assertSame(1, $reg->schemaVersion());
        $this->assertNotSame([], $reg->ids());
        $this->assertTrue($reg->has('openai'));
        $openai = $reg->get('openai');
        $this->assertSame('OpenAI', $openai['name']);
        $this->assertContains('llm', $openai['capabilities']);
        $this->assertArrayHasKey('request_templates', $openai);
    }

    #[Test]
    public function unknown_provider_throws(): void
    {
        $reg = new ProviderRegistry(config_path('providers.json'));
        $this->expectException(ProviderRegistryException::class);
        $reg->get('no-such-provider-id');
    }

    #[Test]
    public function every_models_json_provider_field_has_catalog_entry(): void
    {
        $path = config_path('models.json');
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        $catalog = new ProviderRegistry(config_path('providers.json'));

        foreach (ModelRegistry::CAPABILITIES as $cap) {
            $list = $data[$cap] ?? [];
            $this->assertIsArray($list);
            foreach ($list as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $id = $entry['id'] ?? '';
                if (! is_string($id) || $id === '') {
                    continue;
                }
                if (! isset($entry['provider']) || ! is_string($entry['provider']) || $entry['provider'] === '') {
                    continue;
                }
                $pid = $entry['provider'];
                $this->assertTrue(
                    $catalog->has($pid),
                    "config/models.json [{$cap}].{$id} references provider \"{$pid}\" missing from config/providers.json",
                );
            }
        }
    }
}
