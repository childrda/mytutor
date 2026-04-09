<?php

namespace Tests\Unit;

use App\Services\Ai\ModelRegistry;
use App\Services\Ai\ModelRegistryException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModelRegistryTest extends TestCase
{
    #[Test]
    public function container_resolves_singleton(): void
    {
        $a = $this->app->make(ModelRegistry::class);
        $b = $this->app->make(ModelRegistry::class);
        $this->assertSame($a, $b);
    }

    #[Test]
    public function loads_default_registry_with_schema_version_1(): void
    {
        $r = new ModelRegistry(config_path('model_registry.json'));
        $this->assertSame(1, $r->schemaVersion());
        $this->assertArrayHasKey('llm', $r->all());
    }

    #[Test]
    public function get_returns_llm_openai_entry(): void
    {
        $r = new ModelRegistry(config_path('model_registry.json'));
        $entry = $r->get('llm', 'openai');
        $this->assertSame('openai', $entry['provider']);
        $this->assertArrayHasKey('request_format', $entry);
    }

    #[Test]
    public function get_returns_stub_pdf_entry(): void
    {
        $r = new ModelRegistry(config_path('model_registry.json'));
        $entry = $r->get('pdf', 'unpdf');
        $this->assertArrayHasKey('_note', $entry);
    }

    #[Test]
    public function has_returns_expected(): void
    {
        $r = new ModelRegistry(config_path('model_registry.json'));
        $this->assertTrue($r->has('image', 'dall-e-3'));
        $this->assertFalse($r->has('llm', 'nonexistent-provider'));
        $this->assertFalse($r->has('not-a-capability', 'openai'));
    }

    #[Test]
    public function get_throws_for_unknown_capability(): void
    {
        $r = new ModelRegistry(config_path('model_registry.json'));
        $this->expectException(ModelRegistryException::class);
        $this->expectExceptionMessage('Unknown model registry capability');
        $r->get('unknown_cap', 'x');
    }

    #[Test]
    public function get_throws_for_unknown_provider(): void
    {
        $r = new ModelRegistry(config_path('model_registry.json'));
        $this->expectException(ModelRegistryException::class);
        $this->expectExceptionMessage('Unknown model registry provider');
        $r->get('llm', 'no-such-llm');
    }

    #[Test]
    public function provider_keys_excludes_meta_style_keys(): void
    {
        $r = new ModelRegistry(config_path('model_registry.json'));
        $keys = $r->providerKeys('llm');
        $this->assertContains('openai', $keys);
        $this->assertNotContains('_meta', $keys);
    }

    #[Test]
    public function throws_when_file_missing(): void
    {
        $this->expectException(ModelRegistryException::class);
        $this->expectExceptionMessage('not found');
        new ModelRegistry('/nonexistent/model_registry_xyz.json');
    }

    #[Test]
    public function throws_when_schema_version_wrong(): void
    {
        $path = sys_get_temp_dir().'/mytutor_model_registry_test_'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(['schema_version' => 99, 'llm' => [], 'image' => [], 'tts' => [], 'asr' => [], 'pdf' => [], 'video' => [], 'web_search' => []], JSON_THROW_ON_ERROR));
        try {
            $this->expectException(ModelRegistryException::class);
            $this->expectExceptionMessage('schema_version');
            new ModelRegistry($path);
        } finally {
            @unlink($path);
        }
    }
}
