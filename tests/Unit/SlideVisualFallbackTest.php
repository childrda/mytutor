<?php

namespace Tests\Unit;

use App\Support\LessonGeneration\SlideVisualFallback;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlideVisualFallbackTest extends TestCase
{
    public function test_skips_when_slide_visual_fallback_disabled(): void
    {
        config(['tutor.lesson_generation.slide_visual_fallback' => false]);

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

        $this->assertSame($scene, $out);
    }

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

    public function test_replaces_gen_img_placeholder_when_key_ideas_card_present(): void
    {
        $scene = [
            'type' => 'slide',
            'title' => 'What Is the Water Cycle?',
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'What Is the Water Cycle?',
                    'width' => 1000,
                    'height' => 562.5,
                    'elements' => [
                        [
                            'type' => 'image',
                            'id' => 'img1',
                            'x' => 40,
                            'y' => 165,
                            'width' => 450,
                            'height' => 340,
                            'src' => 'gen_img_1',
                            'alt' => 'Labeled water cycle diagram for students.',
                        ],
                        [
                            'type' => 'card',
                            'id' => 'c1',
                            'x' => 510,
                            'y' => 165,
                            'width' => 450,
                            'height' => 340,
                            'title' => 'Key ideas',
                            'bullets' => ['Evaporation', 'Condensation', 'Precipitation'],
                            'accent' => 'sky',
                        ],
                    ],
                ],
            ],
        ];

        $out = SlideVisualFallback::applyToScene($scene, 'water cycle science');

        $els = $out['content']['canvas']['elements'];
        $this->assertCount(2, $els);
        $this->assertSame('image', $els[0]['type']);
        $this->assertStringContainsString('upload.wikimedia.org', (string) $els[0]['src']);
        $this->assertSame('Labeled water cycle diagram for students.', (string) $els[0]['alt']);
        $this->assertSame('card', $els[1]['type']);
    }

    public function test_replaces_legacy_ai_generate_placeholder_when_key_ideas_card_present(): void
    {
        $scene = [
            'type' => 'slide',
            'title' => 'What Is the Water Cycle?',
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'What Is the Water Cycle?',
                    'width' => 1000,
                    'height' => 562.5,
                    'elements' => [
                        [
                            'type' => 'image',
                            'id' => 'img1',
                            'x' => 40,
                            'y' => 165,
                            'width' => 450,
                            'height' => 340,
                            'src' => 'ai_generate:pending',
                            'alt' => 'Labeled water cycle diagram for students.',
                        ],
                        [
                            'type' => 'card',
                            'id' => 'c1',
                            'x' => 510,
                            'y' => 165,
                            'width' => 450,
                            'height' => 340,
                            'title' => 'Key ideas',
                            'bullets' => ['Evaporation', 'Condensation'],
                            'accent' => 'sky',
                        ],
                    ],
                ],
            ],
        ];

        $out = SlideVisualFallback::applyToScene($scene, 'water cycle science');

        $els = $out['content']['canvas']['elements'];
        $this->assertCount(2, $els);
        $this->assertSame('image', $els[0]['type']);
        $this->assertStringContainsString('upload.wikimedia.org', (string) $els[0]['src']);
        $this->assertSame('Labeled water cycle diagram for students.', (string) $els[0]['alt']);
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

    public function test_replaces_gen_img_with_aircraft_forces_diagram_when_topic_matches(): void
    {
        $scene = [
            'type' => 'slide',
            'title' => 'How the four forces balance',
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'How the four forces balance',
                    'width' => 1000,
                    'height' => 562.5,
                    'elements' => [
                        [
                            'type' => 'image',
                            'id' => 'img1',
                            'x' => 40,
                            'y' => 165,
                            'width' => 450,
                            'height' => 340,
                            'src' => 'gen_img_1',
                            'alt' => 'Four forces on an airplane',
                        ],
                        [
                            'type' => 'card',
                            'id' => 'c1',
                            'x' => 510,
                            'y' => 165,
                            'width' => 450,
                            'height' => 340,
                            'title' => 'Key ideas',
                            'bullets' => ['Lift', 'Weight'],
                            'accent' => 'sky',
                        ],
                    ],
                ],
            ],
        ];

        $out = SlideVisualFallback::applyToScene($scene, 'what makes an airplane fly');

        $els = $out['content']['canvas']['elements'];
        $this->assertCount(2, $els);
        $this->assertSame('image', $els[0]['type']);
        $this->assertStringContainsString('Forces_on_an_aircraft', (string) $els[0]['src']);
        $this->assertSame('Four forces on an airplane', (string) $els[0]['alt']);
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
