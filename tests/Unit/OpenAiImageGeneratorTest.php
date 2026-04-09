<?php

namespace Tests\Unit;

use App\Services\MediaGeneration\ImageGenerationException;
use App\Services\MediaGeneration\OpenAiImageGenerator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAiImageGeneratorTest extends TestCase
{
    #[Test]
    public function gpt_image_model_omits_response_format_parameter(): void
    {
        config([
            'tutor.image_generation.api_key' => 'sk-test',
            'tutor.image_generation.base_url' => 'https://api.openai.com/v1',
            'tutor.image_generation.model' => 'gpt-image-1',
            'tutor.image_generation.default_size' => '1024x1024',
        ]);

        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'data' => [
                    ['b64_json' => base64_encode('fake-image')],
                ],
            ], 200),
        ]);

        $out = app(OpenAiImageGenerator::class)->generate('A diagram');

        $this->assertSame('fake-image', $out['binary']);
        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'images/generations')) {
                return false;
            }
            $body = $request->data();

            return $body === [
                'model' => 'gpt-image-1',
                'prompt' => 'A diagram',
                'n' => 1,
                'size' => '1024x1024',
            ];
        });
    }

    #[Test]
    public function dall_e_model_also_omits_response_format_for_gateway_compatibility(): void
    {
        config([
            'tutor.image_generation.api_key' => 'sk-test',
            'tutor.image_generation.base_url' => 'https://api.openai.com/v1',
            'tutor.image_generation.model' => 'dall-e-3',
            'tutor.image_generation.default_size' => '1024x1024',
        ]);

        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'data' => [
                    ['b64_json' => base64_encode('x')],
                ],
            ], 200),
        ]);

        app(OpenAiImageGenerator::class)->generate('A diagram');

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'images/generations')) {
                return false;
            }
            $body = $request->data();

            return $body === [
                'model' => 'dall-e-3',
                'prompt' => 'A diagram',
                'n' => 1,
                'size' => '1024x1024',
            ];
        });
    }

    #[Test]
    public function decodes_image_from_url_when_b64_json_absent(): void
    {
        config([
            'tutor.image_generation.api_key' => 'sk-test',
            'tutor.image_generation.base_url' => 'https://api.openai.com/v1',
            'tutor.image_generation.model' => 'gpt-image-1',
            'tutor.image_generation.default_size' => '1024x1024',
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'images/generations')) {
                return Http::response([
                    'data' => [
                        ['url' => 'https://files.example.test/img.png'],
                    ],
                ], 200);
            }
            if (str_contains($request->url(), 'files.example.test')) {
                return Http::response('png-binary', 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('not found', 404);
        });

        $out = app(OpenAiImageGenerator::class)->generate('A diagram');

        $this->assertSame('png-binary', $out['binary']);
        $this->assertSame('image/png', $out['mime']);
    }

    #[Test]
    public function throws_when_neither_b64_nor_url_present(): void
    {
        config([
            'tutor.image_generation.api_key' => 'sk-test',
            'tutor.image_generation.base_url' => 'https://api.openai.com/v1',
            'tutor.image_generation.model' => 'gpt-image-1',
            'tutor.image_generation.default_size' => '1024x1024',
        ]);

        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'data' => [
                    ['revised_prompt' => 'x'],
                ],
            ], 200),
        ]);

        $this->expectException(ImageGenerationException::class);
        $this->expectExceptionMessage('b64_json or data[0].url');

        app(OpenAiImageGenerator::class)->generate('A diagram');
    }
}
