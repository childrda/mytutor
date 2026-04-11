<?php

namespace Tests\Unit;

use App\Support\LessonGeneration\SlideAiImageHydration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SlideAiImageHydrationTest extends TestCase
{
    public function test_hydrate_returns_scenes_and_failures_shape_when_api_key_missing(): void
    {
        config(['tutor.image_generation.api_key' => '']);
        $out = SlideAiImageHydration::hydrateScenes([], 'topic', 'en');
        $this->assertSame([], $out['scenes']);
        $this->assertSame([], $out['failures']);
    }

    public function test_progress_callback_reports_planned_and_each_completed_image(): void
    {
        config([
            'tutor.image_generation.api_key' => 'sk-test',
            'tutor.image_generation.base_url' => 'https://api.openai.com/v1',
            'tutor.image_generation.model' => 'dall-e-3',
            'tutor.image_generation.default_size' => '1024x1024',
            'tutor.active.image' => '',
            'tutor.lesson_generation.ai_slide_images_max' => 12,
            'tutor.media_generation.disk' => 'public',
        ]);
        Storage::fake('public');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'images/generations')) {
                return Http::response([
                    'data' => [
                        ['b64_json' => base64_encode('fake-bytes')],
                    ],
                ], 200);
            }

            return Http::response('unexpected URL in test', 500);
        });

        $scenes = [
            [
                'type' => 'slide',
                'title' => 'Intro',
                'content' => [
                    'type' => 'slide',
                    'canvas' => [
                        'elements' => [
                            ['type' => 'image', 'src' => '', 'alt' => 'a'],
                            ['type' => 'image', 'src' => '', 'alt' => 'b'],
                        ],
                    ],
                ],
            ],
        ];

        $events = [];
        SlideAiImageHydration::hydrateScenes($scenes, 'topic', 'en', function (int $completed, int $planned) use (&$events): void {
            $events[] = [$completed, $planned];
        });

        $this->assertSame([
            [0, 2],
            [1, 2],
            [2, 2],
        ], $events);
    }

    #[DataProvider('needsGenProvider')]
    public function test_src_needs_generation(string $src, bool $expected): void
    {
        $this->assertSame($expected, SlideAiImageHydration::srcNeedsGeneration($src));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function needsGenProvider(): array
    {
        return [
            'empty' => ['', true],
            'ai sentinel' => ['ai_generate:pending', true],
            'gen_img placeholder' => ['gen_img_1', true],
            'data url' => ['data:image/png;base64,abc', false],
            'wikimedia' => ['https://upload.wikimedia.org/wikipedia/commons/thumb/1/1/a.png/100px-a.png', false],
            'no image svg' => ['https://upload.wikimedia.org/wikipedia/commons/thumb/a/ac/No_image_available.svg/480px-No_image_available.svg.png', true],
            'example.com' => ['https://example.com/x.png', true],
            'unfilled pdf ref' => ['pdf_page:9', true],
            'public disk url' => ['/storage/generated/2026/04/10/abc.png', false],
            'other root-relative path' => ['/media/lesson/x.png', false],
            'protocol-relative url' => ['//cdn.example.com/x.png', true],
        ];
    }
}
