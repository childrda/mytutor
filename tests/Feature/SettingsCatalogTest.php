<?php

namespace Tests\Feature;

use App\Models\TutorRegistryActive;
use App\Models\User;
use App\Services\Ai\ModelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SettingsCatalogTest extends TestCase
{
    use RefreshDatabase;

    private string $modelsTempPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists(storage_path('framework/testing'));
        $this->modelsTempPath = storage_path('framework/testing/models_catalog_'.uniqid('', true).'.json');
        File::copy(config_path('models.json'), $this->modelsTempPath);
        config(['tutor.models_json_path' => $this->modelsTempPath]);
        app()->forgetInstance(ModelRegistry::class);
    }

    protected function tearDown(): void
    {
        if ($this->modelsTempPath !== '' && is_file($this->modelsTempPath)) {
            @unlink($this->modelsTempPath);
        }
        parent::tearDown();
    }

    #[Test]
    public function guest_cannot_access_catalog_providers(): void
    {
        $this->getJson('/settings/catalog/providers')->assertUnauthorized();
    }

    #[Test]
    public function authenticated_user_can_read_providers_and_models(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/settings/catalog/providers')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['providers']);

        $this->actingAs($user)
            ->getJson('/settings/catalog/models')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['models']);

        $this->actingAs($user)
            ->getJson('/settings/catalog/models/llm')
            ->assertOk()
            ->assertJsonPath('capability', 'llm')
            ->assertJsonStructure(['models']);
    }

    #[Test]
    public function catalog_active_matches_registry_active_shape(): void
    {
        $user = User::factory()->create();
        config(['tutor.active.llm' => null]);

        $this->actingAs($user)
            ->getJson('/settings/catalog/active')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('effective.llm', null);
    }

    #[Test]
    public function can_add_stub_model_and_delete(): void
    {
        $user = User::factory()->create();
        $id = 'catalog-stub-'.bin2hex(random_bytes(4));

        $this->actingAs($user)
            ->postJson('/settings/catalog/models/llm', [
                'id' => $id,
                'provider' => 'openai',
                'display_name' => 'Catalog test stub',
                '_note' => 'Temporary stub for feature test',
            ])
            ->assertOk()
            ->assertJsonPath('saved', true);

        $raw = json_decode((string) file_get_contents($this->modelsTempPath), true);
        $this->assertIsArray($raw);
        $ids = array_column($raw['llm'] ?? [], 'id');
        $this->assertContains($id, $ids);

        $this->actingAs($user)
            ->deleteJson('/settings/catalog/models/llm/'.$id)
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $rawAfter = json_decode((string) file_get_contents($this->modelsTempPath), true);
        $idsAfter = array_column($rawAfter['llm'] ?? [], 'id');
        $this->assertNotContains($id, $idsAfter);
    }

    #[Test]
    public function delete_fails_when_model_is_saved_active(): void
    {
        $user = User::factory()->create();
        config(['tutor.active.llm' => null]);
        TutorRegistryActive::query()->create(['capability' => 'llm', 'active_key' => 'openai']);

        $this->actingAs($user)
            ->deleteJson('/settings/catalog/models/llm/openai')
            ->assertStatus(409);
    }

    #[Test]
    public function delete_bundle_removes_multiple_rows(): void
    {
        $user = User::factory()->create();
        $a = 'bundle-a-'.bin2hex(random_bytes(3));
        $b = 'bundle-b-'.bin2hex(random_bytes(3));

        $this->actingAs($user)
            ->postJson('/settings/catalog/models/llm', [
                'id' => $a,
                'provider' => 'openai',
                'display_name' => 'Bundle A',
                'base_url' => 'https://bundle.test/v1',
                '_note' => 'x',
            ])
            ->assertOk();
        $this->actingAs($user)
            ->postJson('/settings/catalog/models/llm', [
                'id' => $b,
                'provider' => 'openai',
                'display_name' => 'Bundle B',
                'base_url' => 'https://bundle.test/v1',
                '_note' => 'y',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->postJson('/settings/catalog/models/llm/delete-bundle', [
                'row_ids' => [$a, $b],
            ])
            ->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('removed', 2);

        $raw = json_decode((string) file_get_contents($this->modelsTempPath), true);
        $ids = array_column($raw['llm'] ?? [], 'id');
        $this->assertNotContains($a, $ids);
        $this->assertNotContains($b, $ids);
    }

    #[Test]
    public function delete_bundle_fails_when_any_row_is_active(): void
    {
        $user = User::factory()->create();
        config(['tutor.active.llm' => null]);
        $a = 'bundle-act-'.bin2hex(random_bytes(3));
        $b = 'bundle-ok-'.bin2hex(random_bytes(3));

        $this->actingAs($user)
            ->postJson('/settings/catalog/models/llm', [
                'id' => $a,
                'provider' => 'openai',
                'display_name' => 'Active target',
                'base_url' => 'https://bundle2.test/v1',
                '_note' => 'x',
            ])
            ->assertOk();
        $this->actingAs($user)
            ->postJson('/settings/catalog/models/llm', [
                'id' => $b,
                'provider' => 'openai',
                'display_name' => 'Other',
                'base_url' => 'https://bundle2.test/v1',
                '_note' => 'y',
            ])
            ->assertOk();

        TutorRegistryActive::query()->create(['capability' => 'llm', 'active_key' => $a]);

        $this->actingAs($user)
            ->postJson('/settings/catalog/models/llm/delete-bundle', [
                'row_ids' => [$a, $b],
            ])
            ->assertStatus(409);
    }

    #[Test]
    public function put_active_via_catalog_persists(): void
    {
        $user = User::factory()->create();
        config(['tutor.active.llm' => null]);

        $this->actingAs($user)
            ->putJson('/settings/catalog/active', [
                'active' => ['llm' => 'openai'],
            ])
            ->assertOk()
            ->assertJsonPath('saved', true);

        $this->assertDatabaseHas('tutor_registry_actives', [
            'capability' => 'llm',
            'active_key' => 'openai',
        ]);
    }

    #[Test]
    public function provider_base_url_save_updates_image_openai_and_returns_models(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/settings/catalog/models/image/provider-base-url', [
                'provider' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'row_ids' => ['dall-e-3', 'dall-e-2', 'gpt-image-1'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('saved', true)
            ->assertJsonPath('updated_rows', 3)
            ->assertJsonStructure(['models']);

        $raw = json_decode((string) file_get_contents($this->modelsTempPath), true);
        $this->assertIsArray($raw);
        foreach (['dall-e-3', 'dall-e-2', 'gpt-image-1'] as $id) {
            $row = collect($raw['image'] ?? [])->firstWhere('id', $id);
            $this->assertIsArray($row);
            $this->assertSame('https://api.openai.com/v1', $row['base_url'] ?? null);
        }
    }
}
