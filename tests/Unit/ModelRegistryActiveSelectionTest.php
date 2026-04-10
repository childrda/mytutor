<?php

namespace Tests\Unit;

use App\Services\Ai\ModelRegistry;
use App\Services\Ai\ModelRegistryException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModelRegistryActiveSelectionTest extends TestCase
{
    protected function tearDown(): void
    {
        config([
            'tutor.active.llm' => null,
            'tutor.active.image' => null,
        ]);

        parent::tearDown();
    }

    #[Test]
    public function active_key_null_when_not_configured(): void
    {
        config(['tutor.active.llm' => null]);
        $r = new ModelRegistry(config_path('models.json'));
        $this->assertNull($r->activeKey('llm'));
        $this->assertFalse($r->hasActive('llm'));
    }

    #[Test]
    public function active_key_trims_string(): void
    {
        config(['tutor.active.llm' => '  openai  ']);
        $r = new ModelRegistry(config_path('models.json'));
        $this->assertSame('openai', $r->activeKey('llm'));
        $this->assertTrue($r->hasActive('llm'));
    }

    #[Test]
    public function active_entry_matches_get(): void
    {
        config(['tutor.active.llm' => 'openai']);
        $r = new ModelRegistry(config_path('models.json'));
        $this->assertSame($r->get('llm', 'openai'), $r->activeEntry('llm'));
    }

    #[Test]
    public function active_entry_throws_when_unset(): void
    {
        config(['tutor.active.llm' => '']);
        $r = new ModelRegistry(config_path('models.json'));

        $this->expectException(ModelRegistryException::class);
        $this->expectExceptionMessage('No active model registry key');

        $r->activeEntry('llm');
    }

    #[Test]
    public function active_entry_throws_when_key_unknown(): void
    {
        config(['tutor.active.llm' => 'not-a-real-registry-llm']);
        $r = new ModelRegistry(config_path('models.json'));

        $this->expectException(ModelRegistryException::class);
        $this->expectExceptionMessage('Unknown models.json model id');

        $r->activeEntry('llm');
    }

    #[Test]
    public function active_key_rejects_unknown_capability(): void
    {
        $r = new ModelRegistry(config_path('models.json'));
        $this->expectException(ModelRegistryException::class);
        $r->activeKey('invalid_capability');
    }
}
