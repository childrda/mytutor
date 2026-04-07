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
