<?php

namespace Tests\Unit;

use App\Services\MediaGeneration\ImageGenerationException;
use App\Services\MediaGeneration\OpenAiImageGenerator;
use App\Support\ApiJson;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAiImageGeneratorRegistryPathTest extends TestCase
{
    #[Test]
    public function active_image_uses_registry_template_for_request_body(): void
    {
        config([
            'tutor.active.image' => 'dall-e-3',
            'tutor.image_generation.api_key' => 'sk-test',
            'tutor.image_generation.base_url' => 'https://api.openai.com/v1',
            'tutor.image_generation.model' => 'dall-e-3',
            'tutor.image_generation.default_size' => '1024x1024',
        ]);

        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'data' => [
                    ['b64_json' => base64_encode('registry-bytes')],
                ],
            ], 200),
            'https://api.anthropic.com/v1/images/generations' => Http::response([
                'data' => [
                    ['b64_json' => base64_encode('registry-bytes')],
                ],
            ], 200),
        ]);

        $out = app(OpenAiImageGenerator::class)->generate('A cat');

        $this->assertSame('registry-bytes', $out['binary']);
        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'images/generations')) {
                return false;
            }

            return in_array(parse_url($request->url(), PHP_URL_HOST), ['api.openai.com', 'api.anthropic.com'], true)
                && $request->data() === [
                    'model' => 'dall-e-3',
                    'prompt' => 'A cat',
                    'n' => 1,
                    'size' => '1024x1024',
                ];
        });
    }

    #[Test]
    public function stub_provider_without_request_format_throws_before_http(): void
    {
        config([
            'tutor.active.image' => 'seedream',
            'tutor.image_generation.api_key' => 'sk-test',
            'tutor.image_generation.base_url' => 'https://api.openai.com/v1',
            'tutor.image_generation.model' => 'dall-e-3',
            'tutor.image_generation.default_size' => '1024x1024',
        ]);

        Http::fake();

        try {
            app(OpenAiImageGenerator::class)->generate('x');
            $this->fail('Expected ImageGenerationException');
        } catch (ImageGenerationException $e) {
            $this->assertStringContainsString('request_format (stub)', $e->getMessage());
            $this->assertStringContainsString('seedream', $e->getMessage());
            $this->assertSame(ApiJson::INVALID_REQUEST, $e->errorCode);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function registry_http_401_maps_to_missing_api_key(): void
    {
        config([
            'tutor.active.image' => 'dall-e-3',
            'tutor.image_generation.api_key' => 'sk-bad',
            'tutor.image_generation.base_url' => 'https://api.openai.com/v1',
            'tutor.image_generation.model' => 'dall-e-3',
            'tutor.image_generation.default_size' => '1024x1024',
            'tutor.image_generation.http_max_attempts' => 1,
        ]);

        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'error' => ['message' => 'invalid'],
            ], 401),
            'https://api.anthropic.com/v1/images/generations' => Http::response([
                'error' => ['message' => 'invalid'],
            ], 401),
        ]);

        try {
            app(OpenAiImageGenerator::class)->generate('A cat');
            $this->fail('Expected ImageGenerationException');
        } catch (ImageGenerationException $e) {
            $this->assertSame('Invalid or missing API key', $e->getMessage());
            $this->assertSame(ApiJson::MISSING_API_KEY, $e->errorCode);
            $this->assertSame(401, $e->httpStatus);
        }
    }
}
