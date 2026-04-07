<?php

namespace Tests\Feature;

use App\Services\MediaGeneration\GeneratedMediaStorage;
use App\Support\ApiJson;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateImageTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function missing_prompt_returns_400(): void
    {
        config(['tutor.image_generation.api_key' => 'sk-test']);

        $response = $this->postJson('/api/generate/image', [
            'prompt' => '   ',
            'requiresApiKey' => false,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('errorCode', ApiJson::MISSING_REQUIRED_FIELD);
    }

    #[Test]
    public function missing_api_key_returns_401_when_required(): void
    {
        config([
            'tutor.image_generation.api_key' => '',
            'tutor.default_chat.api_key' => '',
        ]);

        $response = $this->postJson('/api/generate/image', [
            'prompt' => 'A red apple',
            'requiresApiKey' => true,
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('errorCode', ApiJson::MISSING_API_KEY);
    }

    #[Test]
    public function successful_generation_returns_url_and_stores_via_storage(): void
    {
        config([
            'tutor.image_generation.api_key' => 'sk-test',
            'tutor.image_generation.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (! str_contains($request->url(), 'api.openai.com/v1/images/generations')) {
                return Http::response('not found', 404);
            }

            return Http::response([
                'data' => [
                    ['b64_json' => base64_encode('fake-png-binary')],
                ],
            ], 200);
        });

        $mockStorage = Mockery::mock(GeneratedMediaStorage::class);
        $mockStorage->shouldReceive('storeBinary')
            ->once()
            ->with('image', 'png', 'fake-png-binary')
            ->andReturn([
                'relativePath' => 'generated/image/2026/01/01/01hz.png',
                'url' => 'https://app.test/storage/generated/image/2026/01/01/01hz.png',
            ]);
        $this->app->instance(GeneratedMediaStorage::class, $mockStorage);

        $response = $this->postJson('/api/generate/image', [
            'prompt' => 'A simple test image',
            'requiresApiKey' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('provider', 'openai-images')
            ->assertJsonPath('path', 'generated/image/2026/01/01/01hz.png')
            ->assertJsonPath('url', 'https://app.test/storage/generated/image/2026/01/01/01hz.png');
    }
}
