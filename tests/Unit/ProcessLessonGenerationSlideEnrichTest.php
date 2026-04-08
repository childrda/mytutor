<?php

namespace Tests\Unit;

use App\Jobs\ProcessLessonGenerationJob;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class ProcessLessonGenerationSlideEnrichTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $scene
     * @return array<string, mixed>
     */
    private static function enrich(array $scene): array
    {
        $m = (new ReflectionClass(ProcessLessonGenerationJob::class))->getMethod('enrichSlideScene');
        $m->setAccessible(true);

        return $m->invoke(null, $scene, '', '');
    }

    #[Test]
    public function maps_content_body_to_text_element_when_elements_empty(): void
    {
        $out = self::enrich([
            'type' => 'slide',
            'title' => 'Evaporation',
            'content' => [
                'type' => 'slide',
                'canvas' => ['title' => 'Evaporation', 'elements' => []],
                'body' => "Water heats up and becomes vapor.\nThis happens at the surface.",
            ],
        ]);

        $els = $out['content']['canvas']['elements'];
        $this->assertCount(1, $els);
        $this->assertSame('text', $els[0]['type']);
        $this->assertStringContainsString('vapor', $els[0]['text']);
        $this->assertArrayNotHasKey('body', $out['content']);
    }

    #[Test]
    public function preserves_image_element_id_when_id_is_numeric_from_json(): void
    {
        $out = self::enrich([
            'type' => 'slide',
            'title' => 'Test',
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'Test',
                    'elements' => [
                        [
                            'type' => 'image',
                            'id' => 404,
                            'x' => 40,
                            'y' => 100,
                            'width' => 400,
                            'height' => 300,
                            'src' => 'gen_img_1',
                            'alt' => 'Alt',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('404', $out['content']['canvas']['elements'][0]['id']);
    }

    #[Test]
    public function preserves_nonempty_text_elements(): void
    {
        $out = self::enrich([
            'type' => 'slide',
            'title' => 'Hi',
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'Hi',
                    'elements' => [
                        [
                            'type' => 'text',
                            'id' => 't1',
                            'x' => 10,
                            'y' => 20,
                            'width' => 100,
                            'height' => 50,
                            'fontSize' => 18,
                            'text' => 'Hello world',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $out['content']['canvas']['elements']);
        $this->assertSame('Hello world', $out['content']['canvas']['elements'][0]['text']);
        $this->assertSame('t1', $out['content']['canvas']['elements'][0]['id']);
    }

    #[Test]
    public function coerces_elements_with_text_but_no_type_to_text(): void
    {
        $out = self::enrich([
            'type' => 'slide',
            'title' => 'Hi',
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'Hi',
                    'elements' => [
                        ['text' => 'Body without type field', 'x' => 10, 'y' => 20, 'width' => 400, 'height' => 80, 'fontSize' => 18],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $out['content']['canvas']['elements']);
        $this->assertSame('text', $out['content']['canvas']['elements'][0]['type']);
        $this->assertSame('Body without type field', $out['content']['canvas']['elements'][0]['text']);
    }

    #[Test]
    public function preserves_card_elements_alongside_text(): void
    {
        $out = self::enrich([
            'type' => 'slide',
            'title' => 'Concepts',
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'Concepts',
                    'elements' => [
                        [
                            'type' => 'text',
                            'id' => 'h1',
                            'x' => 48,
                            'y' => 72,
                            'width' => 900,
                            'height' => 48,
                            'fontSize' => 24,
                            'text' => 'Three pillars',
                        ],
                        [
                            'type' => 'card',
                            'id' => 'c1',
                            'x' => 40,
                            'y' => 220,
                            'width' => 290,
                            'height' => 300,
                            'title' => 'Idea A',
                            'bullets' => ['Line one', 'Line two'],
                            'caption' => 'A → B',
                            'accent' => 'emerald',
                            'icon' => '🌿',
                        ],
                    ],
                ],
            ],
        ]);

        $els = $out['content']['canvas']['elements'];
        $this->assertCount(2, $els);
        $this->assertSame('text', $els[0]['type']);
        $this->assertSame('card', $els[1]['type']);
        $this->assertSame('c1', $els[1]['id']);
        $this->assertSame('emerald', $els[1]['accent']);
        $this->assertSame('🌿', $els[1]['icon']);
        $this->assertSame(['Line one', 'Line two'], $els[1]['bullets']);
        $this->assertSame('A → B', $els[1]['caption']);
    }

    #[Test]
    public function maps_empty_image_src_to_ai_generate_pending(): void
    {
        $out = self::enrich([
            'type' => 'slide',
            'title' => 'Lift',
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'Lift',
                    'elements' => [
                        [
                            'type' => 'image',
                            'id' => 'im1',
                            'x' => 40,
                            'y' => 100,
                            'width' => 400,
                            'height' => 300,
                            'src' => '',
                            'alt' => 'Diagram of lift on a wing',
                        ],
                    ],
                ],
            ],
        ]);

        $els = $out['content']['canvas']['elements'];
        $this->assertCount(1, $els);
        $this->assertSame('image', $els[0]['type']);
        $this->assertSame('ai_generate:pending', $els[0]['src']);
        $this->assertSame('Diagram of lift on a wing', $els[0]['alt']);
    }

    #[Test]
    public function uses_stage_description_as_last_resort_fallback(): void
    {
        $m = (new ReflectionClass(ProcessLessonGenerationJob::class))->getMethod('enrichSlideScene');
        $m->setAccessible(true);
        $out = $m->invoke(null, [
            'type' => 'slide',
            'title' => 'Intro',
            'content' => ['type' => 'slide', 'canvas' => ['title' => 'Intro', 'elements' => []]],
        ], 'Stage description for learners about water.', '');

        $this->assertStringContainsString('water', strtolower($out['content']['canvas']['elements'][0]['text']));
    }
}
