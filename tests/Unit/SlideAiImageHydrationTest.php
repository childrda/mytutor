<?php

namespace Tests\Unit;

use App\Support\LessonGeneration\SlideAiImageHydration;
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
        ];
    }
}
