<?php

namespace Tests\Unit;

use App\Support\LessonGeneration\PdfPageImageHydration;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PdfPageImageHydrationTest extends TestCase
{
    #[Test]
    public function it_replaces_pdf_page_placeholders_with_data_urls(): void
    {
        $dataUrl = 'data:image/jpeg;base64,abcd';
        $scenes = [
            [
                'type' => 'slide',
                'content' => [
                    'type' => 'slide',
                    'canvas' => [
                        'elements' => [
                            ['type' => 'image', 'src' => 'pdf_page:1', 'id' => 'a'],
                            ['type' => 'text', 'text' => 'Hi', 'id' => 'b'],
                        ],
                    ],
                ],
            ],
        ];

        $out = PdfPageImageHydration::hydrateScenes($scenes, [$dataUrl]);

        $els = $out[0]['content']['canvas']['elements'];
        $this->assertSame($dataUrl, $els[0]['src']);
        $this->assertSame('Hi', $els[1]['text']);
    }

    #[Test]
    public function it_leaves_unknown_page_indices_unchanged(): void
    {
        $scenes = [
            [
                'type' => 'slide',
                'content' => [
                    'type' => 'slide',
                    'canvas' => [
                        'elements' => [
                            ['type' => 'image', 'src' => 'pdf_page:99', 'id' => 'a'],
                        ],
                    ],
                ],
            ],
        ];

        $out = PdfPageImageHydration::hydrateScenes($scenes, ['data:image/jpeg;base64,xx']);

        $this->assertSame('pdf_page:99', $out[0]['content']['canvas']['elements'][0]['src']);
    }

    #[Test]
    public function it_skips_non_slide_scenes(): void
    {
        $scenes = [
            [
                'type' => 'quiz',
                'content' => ['type' => 'quiz', 'questions' => []],
            ],
        ];

        $out = PdfPageImageHydration::hydrateScenes($scenes, ['data:image/jpeg;base64,xx']);

        $this->assertSame($scenes, $out);
    }
}
