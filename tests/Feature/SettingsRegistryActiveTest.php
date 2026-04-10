<?php

namespace Tests\Feature;

use App\Models\TutorRegistryActive;
use App\Models\User;
use App\Services\Ai\ModelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SettingsRegistryActiveTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_cannot_read_registry_active(): void
    {
        $this->getJson('/settings/registry-active')->assertUnauthorized();
    }

    #[Test]
    public function authenticated_user_can_read_registry_active(): void
    {
        $user = User::factory()->create();
        config(['tutor.active.llm' => null]);

        $this->actingAs($user)
            ->getJson('/settings/registry-active')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('capabilities.0', 'llm')
            ->assertJsonPath('effective.llm', null);
    }

    #[Test]
    public function update_rejects_unknown_provider(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/settings/registry-active', [
                'active' => ['llm' => 'not-a-real-key-xyz'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function update_persists_and_effective_reflects_database_when_env_unset(): void
    {
        $user = User::factory()->create();
        config(['tutor.active.llm' => null]);

        $this->actingAs($user)
            ->putJson('/settings/registry-active', [
                'active' => ['llm' => 'openai'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('tutor_registry_actives', [
            'capability' => 'llm',
            'active_key' => 'openai',
        ]);

        $this->actingAs($user)
            ->getJson('/settings/registry-active')
            ->assertJsonPath('effective.llm', 'openai');
    }

    #[Test]
    public function clear_with_empty_string_deletes_row(): void
    {
        $user = User::factory()->create();
        config(['tutor.active.llm' => null]);
        TutorRegistryActive::query()->create(['capability' => 'llm', 'active_key' => 'openai']);

        $this->actingAs($user)
            ->putJson('/settings/registry-active', [
                'active' => ['llm' => null],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('tutor_registry_actives', [
            'capability' => 'llm',
            'active_key' => 'openai',
        ]);
    }

    #[Test]
    public function env_config_still_wins_in_effective_response(): void
    {
        $user = User::factory()->create();
        config(['tutor.active.llm' => 'anthropic']);
        TutorRegistryActive::query()->create(['capability' => 'llm', 'active_key' => 'openai']);

        $this->actingAs($user)
            ->getJson('/settings/registry-active')
            ->assertJsonPath('configLayer.llm', 'anthropic')
            ->assertJsonPath('effective.llm', 'anthropic')
            ->assertJsonPath('database.llm', 'openai');

        $this->assertSame('anthropic', app(ModelRegistry::class)->activeKey('llm'));
    }
}
