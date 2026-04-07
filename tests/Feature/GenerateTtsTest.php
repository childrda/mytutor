<?php

namespace Tests\Feature;

use App\Services\MediaGeneration\GeneratedMediaStorage;
use App\Support\ApiJson;
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
    public function missing_text_returns_400(): void
    {
        config(['tutor.tts_generation.api_key' => 'sk-test']);

        $response = $this->postJson('/api/generate/tts', [
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

        $response = $this->postJson('/api/generate/tts', [
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

        $response = $this->postJson('/api/generate/tts', [
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

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (! str_contains($request->url(), 'api.openai.com/v1/audio/speech')) {
                return Http::response('not found', 404);
            }

            return Http::response("ID3\x00fake-mp3", 200, [
                'Content-Type' => 'audio/mpeg',
            ]);
        });

        $mockStorage = Mockery::mock(GeneratedMediaStorage::class);
        $mockStorage->shouldReceive('storeBinary')
            ->once()
            ->with('tts', 'mp3', "ID3\x00fake-mp3")
            ->andReturn([
                'relativePath' => 'generated/tts/2026/01/01/x.mp3',
                'url' => 'https://app.test/storage/generated/tts/2026/01/01/x.mp3',
            ]);
        $this->app->instance(GeneratedMediaStorage::class, $mockStorage);

        $response = $this->postJson('/api/generate/tts', [
            'text' => 'Hello world',
            'requiresApiKey' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('provider', 'openai-tts')
            ->assertJsonPath('format', 'mp3')
            ->assertJsonPath('mime', 'audio/mpeg');
    }
}
