<?php

namespace Tests\Unit;

use App\Support\LessonGeneration\SlideVisualFallback;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlideVisualFallbackTest extends TestCase
{
    public function test_injects_diagram_for_text_only_water_cycle_slide(): void
    {
        $scene = [
            'type' => 'slide',
            'title' => 'Introduction to the Water Cycle',
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'Introduction to the Water Cycle',
                    'width' => 1000,
                    'height' => 562.5,
                    'elements' => [
                        [
                            'type' => 'text',
                            'id' => 't1',
                            'x' => 48,
                            'y' => 120,
                            'width' => 900,
                            'height' => 400,
                            'fontSize' => 22,
                            'text' => 'The water cycle is a continuous process.',
                        ],
                    ],
                ],
            ],
        ];

        $out = SlideVisualFallback::applyToScene($scene, 'science unit');

        $els = $out['content']['canvas']['elements'];
        $this->assertGreaterThanOrEqual(2, count($els));
        $this->assertSame('image', $els[0]['type']);
        $this->assertStringContainsString('upload.wikimedia.org', (string) $els[0]['src']);
        $this->assertSame('text', $els[1]['type']);
        $this->assertGreaterThanOrEqual(500, (int) $els[1]['x']);
    }

    public function test_skips_when_slide_already_has_image(): void
    {
        $scene = [
            'type' => 'slide',
            'title' => 'Water Cycle',
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'Water Cycle',
                    'elements' => [
                        [
                            'type' => 'image',
                            'id' => 'i1',
                            'x' => 40,
                            'y' => 100,
                            'width' => 400,
                            'height' => 300,
                            'src' => 'https://example.org/diagram.png',
                            'alt' => '',
                        ],
                    ],
                ],
            ],
        ];

        $out = SlideVisualFallback::applyToScene($scene, 'water cycle');
        $this->assertCount(1, $out['content']['canvas']['elements']);
    }

    public function test_wikimedia_last_resort_when_no_keyword_match(): void
    {
        Http::fake([
            'en.wikipedia.org/w/api.php*' => Http::response([
                'query' => [
                    'pages' => [
                        '42' => [
                            'title' => 'File:Example diagram.png',
                            'imageinfo' => [[
                                'thumburl' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/0/00/Example_test.png/520px-Example_test.png',
                                'url' => 'https://upload.wikimedia.org/wikipedia/commons/0/00/Example_test.png',
                                'mime' => 'image/png',
                            ]],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $scene = [
            'type' => 'slide',
            'title' => 'Market structures',
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'Market structures',
                    'width' => 1000,
                    'height' => 562.5,
                    'elements' => [
                        [
                            'type' => 'text',
                            'id' => 't1',
                            'x' => 48,
                            'y' => 120,
                            'width' => 900,
                            'height' => 400,
                            'fontSize' => 22,
                            'text' => 'Oligopoly and monopolistic competition differ in barriers to entry.',
                        ],
                    ],
                ],
            ],
        ];

        $out = SlideVisualFallback::applyToScene($scene, 'microeconomics market structure comparison');

        $els = $out['content']['canvas']['elements'];
        $this->assertGreaterThanOrEqual(2, count($els));
        $this->assertSame('image', $els[0]['type']);
        $this->assertStringContainsString('upload.wikimedia.org', (string) $els[0]['src']);
        Http::assertSent(static fn ($request) => str_contains($request->url(), 'en.wikipedia.org/w/api.php'));
    }
}
