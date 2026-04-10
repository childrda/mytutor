<?php

namespace Tests\Unit;

use App\Models\TutorRegistryActive;
use App\Services\Ai\TutorActiveRegistrySelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TutorActiveRegistrySelectionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function config_non_empty_wins_over_database(): void
    {
        config(['tutor.active.llm' => 'openai']);
        TutorRegistryActive::query()->create(['capability' => 'llm', 'active_key' => 'anthropic']);

        $sel = new TutorActiveRegistrySelection;

        $this->assertSame('openai', $sel->resolve('llm'));
    }

    #[Test]
    public function uses_database_when_config_empty(): void
    {
        config(['tutor.active.llm' => null]);
        TutorRegistryActive::query()->create(['capability' => 'llm', 'active_key' => 'openai']);

        $sel = new TutorActiveRegistrySelection;

        $this->assertSame('openai', $sel->resolve('llm'));
    }

    #[Test]
    public function returns_null_when_config_empty_and_no_row(): void
    {
        config(['tutor.active.llm' => null]);

        $sel = new TutorActiveRegistrySelection;

        $this->assertNull($sel->resolve('llm'));
    }

    #[Test]
    public function empty_string_config_falls_through_to_database(): void
    {
        config(['tutor.active.llm' => '   ']);
        TutorRegistryActive::query()->create(['capability' => 'llm', 'active_key' => 'openai']);

        $sel = new TutorActiveRegistrySelection;

        $this->assertSame('openai', $sel->resolve('llm'));
    }
}
