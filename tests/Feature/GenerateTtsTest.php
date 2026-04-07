<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MediaGeneration\GeneratedMediaStorage;
use App\Support\ApiJson;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateTtsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function guest_cannot_use_tts(): void
    {
        config(['tutor.tts_generation.api_key' => 'sk-test']);

        $this->postJson('/api/generate/tts', [
            'text' => 'Hello',
            'requiresApiKey' => false,
        ])->assertUnauthorized();
    }

    #[Test]
    public function missing_text_returns_400(): void
    {
        config(['tutor.tts_generation.api_key' => 'sk-test']);

        $response = $this->actingAs(User::factory()->create())->postJson('/api/generate/tts', [
            'text' => '   ',
            'requiresApiKey' => false,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('errorCode', ApiJson::MISSING_REQUIRED_FIELD);
    }

    #[Test]
    public function text_over_limit_returns_400(): void
    {
        config([
            'tutor.tts_generation.api_key' => 'sk-test',
            'tutor.tts_generation.max_input_chars' => 5,
        ]);

        $response = $this->actingAs(User::factory()->create())->postJson('/api/generate/tts', [
            'text' => '123456',
            'requiresApiKey' => false,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('errorCode', ApiJson::INVALID_REQUEST);
    }

    #[Test]
    public function missing_api_key_returns_401_when_required(): void
    {
        config([
            'tutor.tts_generation.api_key' => '',
            'tutor.default_chat.api_key' => '',
        ]);

        $response = $this->actingAs(User::factory()->create())->postJson('/api/generate/tts', [
            'text' => 'Hello',
            'requiresApiKey' => true,
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('errorCode', ApiJson::MISSING_API_KEY);
    }

    #[Test]
    public function successful_tts_returns_url_and_stores_audio(): void
    {
        config([
            'tutor.tts_generation.api_key' => 'sk-test',
            'tutor.tts_generation.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::fake(function (Request $request) {
            if (! str_contains($request->url(), 'api.openai.com/v1/audio/speech')) {
                return Http::response('not found', 404);
            }

            return Http::response("ID3\x00fake-mp3", 200, [
                'Content-Type' => 'audio/mpeg',
            ]);
        });

        $mockStorage = Mockery::mock(GeneratedMediaStorage::class);
        $mockStorage->shouldReceive('getOrStoreFingerprint')
            ->once()
            ->with('tts-cache', Mockery::type('string'), 'mp3', Mockery::type('Closure'))
            ->andReturnUsing(function ($subdir, $fp, $ext, $factory) {
                $binary = $factory();

                return [
                    'relativePath' => 'generated/tts-cache/ab/cd/hash.mp3',
                    'url' => 'https://app.test/storage/generated/tts-cache/ab/cd/hash.mp3',
                    'cacheHit' => false,
                ];
            });
        $this->app->instance(GeneratedMediaStorage::class, $mockStorage);

        $response = $this->actingAs(User::factory()->create())->postJson('/api/generate/tts', [
            'text' => 'Hello world',
            'requiresApiKey' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('provider', 'openai-tts')
            ->assertJsonPath('format', 'mp3')
            ->assertJsonPath('mime', 'audio/mpeg')
            ->assertJsonPath('cached', false);

        Http::assertSentCount(1);
    }

    #[Test]
    public function identical_request_hits_tts_cache_without_second_upstream_call(): void
    {
        config([
            'tutor.tts_generation.api_key' => 'sk-test',
            'tutor.tts_generation.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::fake(function (Request $request) {
            if (! str_contains($request->url(), 'api.openai.com/v1/audio/speech')) {
                return Http::response('not found', 404);
            }

            return Http::response("ID3\x00fake-mp3", 200, [
                'Content-Type' => 'audio/mpeg',
            ]);
        });

        $n = 0;
        $mockStorage = Mockery::mock(GeneratedMediaStorage::class);
        $mockStorage->shouldReceive('getOrStoreFingerprint')
            ->twice()
            ->andReturnUsing(function ($subdir, $fp, $ext, $factory) use (&$n) {
                $n++;
                if ($n === 1) {
                    $factory();
                }

                return [
                    'relativePath' => 'generated/tts-cache/ab/cd/hash.mp3',
                    'url' => 'https://app.test/storage/generated/tts-cache/ab/cd/hash.mp3',
                    'cacheHit' => $n > 1,
                ];
            });
        $this->app->instance(GeneratedMediaStorage::class, $mockStorage);

        $user = User::factory()->create();
        $payload = [
            'text' => 'Cache me',
            'requiresApiKey' => false,
        ];

        $first = $this->actingAs($user)->postJson('/api/generate/tts', $payload);
        $first->assertOk()->assertJsonPath('cached', false);

        $second = $this->actingAs($user)->postJson('/api/generate/tts', $payload);
        $second->assertOk()->assertJsonPath('cached', true);

        Http::assertSentCount(1);
    }
}
