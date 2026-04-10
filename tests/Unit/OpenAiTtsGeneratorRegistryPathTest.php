<?php

namespace Tests\Unit;

use App\Services\MediaGeneration\OpenAiTtsGenerator;
use App\Services\MediaGeneration\TtsGenerationException;
use App\Support\ApiJson;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAiTtsGeneratorRegistryPathTest extends TestCase
{
    #[Test]
    public function active_tts_uses_registry_request_body_including_speed(): void
    {
        config([
            'tutor.active.tts' => 'openai-tts',
            'tutor.tts_generation.api_key' => 'sk-test',
            'tutor.tts_generation.base_url' => 'https://api.openai.com/v1',
            'tutor.tts_generation.model' => 'tts-1',
            'tutor.tts_generation.voice' => 'alloy',
            'tutor.tts_generation.format' => 'mp3',
            'tutor.tts_generation.http_max_attempts' => 1,
        ]);

        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response("\x00mp3", 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $out = app(OpenAiTtsGenerator::class)->generate('Hello world', null, null, null, null, 1.25);

        $this->assertSame("\x00mp3", $out['binary']);
        $this->assertSame('mp3', $out['format']);
        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'audio/speech')) {
                return false;
            }
            $body = $request->data();

            return $body === [
                'model' => 'tts-1',
                'input' => 'Hello world',
                'voice' => 'alloy',
                'response_format' => 'mp3',
                'speed' => 1.25,
            ];
        });
    }

    #[Test]
    public function stub_provider_throws_before_http(): void
    {
        config([
            'tutor.active.tts' => 'azure-tts',
            'tutor.tts_generation.api_key' => 'sk-test',
            'tutor.tts_generation.base_url' => 'https://api.openai.com/v1',
            'tutor.tts_generation.model' => 'tts-1',
            'tutor.tts_generation.voice' => 'alloy',
            'tutor.tts_generation.format' => 'mp3',
        ]);

        Http::fake();

        try {
            app(OpenAiTtsGenerator::class)->generate('Hi');
            $this->fail('Expected TtsGenerationException');
        } catch (TtsGenerationException $e) {
            $this->assertStringContainsString('request_format (stub)', $e->getMessage());
            $this->assertStringContainsString('azure-tts', $e->getMessage());
            $this->assertSame(ApiJson::INVALID_REQUEST, $e->errorCode);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function registry_http_401_maps_to_missing_api_key(): void
    {
        config([
            'tutor.active.tts' => 'openai-tts',
            'tutor.tts_generation.api_key' => 'sk-bad',
            'tutor.tts_generation.base_url' => 'https://api.openai.com/v1',
            'tutor.tts_generation.model' => 'tts-1',
            'tutor.tts_generation.voice' => 'alloy',
            'tutor.tts_generation.format' => 'mp3',
            'tutor.tts_generation.http_max_attempts' => 1,
        ]);

        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response(['error' => ['message' => 'bad']], 401),
        ]);

        try {
            app(OpenAiTtsGenerator::class)->generate('Hi');
            $this->fail('Expected TtsGenerationException');
        } catch (TtsGenerationException $e) {
            $this->assertSame('Invalid or missing API key', $e->getMessage());
            $this->assertSame(ApiJson::MISSING_API_KEY, $e->errorCode);
            $this->assertSame(401, $e->httpStatus);
        }
    }
}
