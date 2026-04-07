<?php

namespace Tests\Unit;

use App\Support\LessonGeneration\SlideVisualFallback;
use PHPUnit\Framework\TestCase;

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
}
