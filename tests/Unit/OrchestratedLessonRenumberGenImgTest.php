<?php

namespace Tests\Unit;

use App\Services\LessonGeneration\OrchestratedLessonGenerationService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class OrchestratedLessonRenumberGenImgTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $scenes
     * @return array{scenes: list<array<string, mixed>>, altMap: array<string, string>}
     */
    private function invokeRenumber(OrchestratedLessonGenerationService $svc, array $scenes): array
    {
        $m = (new ReflectionClass($svc))->getMethod('renumberGenImgPlaceholders');
        $m->setAccessible(true);

        return $m->invoke($svc, $scenes);
    }

    private function slideWithImage(string $src, string $alt = 'Alt A'): array
    {
        return [
            'type' => 'slide',
            'id' => 's1',
            'title' => 'T',
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'Canvas',
                    'elements' => [
                        [
                            'type' => 'image',
                            'id' => 'img',
                            'src' => $src,
                            'alt' => $alt,
                        ],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function duplicate_original_placeholder_reuses_one_canonical_id_and_single_alt_map_entry(): void
    {
        $svc = app(OrchestratedLessonGenerationService::class);
        $scenes = [
            $this->slideWithImage('gen_img_1', 'Diagram A'),
            array_replace_recursive($this->slideWithImage('gen_img_1', 'Ignored second alt'), [
                'id' => 's2',
                'content' => ['canvas' => ['elements' => [[
                    'type' => 'image',
                    'id' => 'img2',
                    'src' => 'gen_img_1',
                    'alt' => 'Ignored second alt',
                ]]]],
            ]),
        ];

        $out = $this->invokeRenumber($svc, $scenes);

        $this->assertSame('gen_img_1', $out['scenes'][0]['content']['canvas']['elements'][0]['src']);
        $this->assertSame('gen_img_1', $out['scenes'][1]['content']['canvas']['elements'][0]['src']);
        $this->assertCount(1, $out['altMap']);
        $this->assertSame('Diagram A', $out['altMap']['gen_img_1']);
    }

    #[Test]
    public function distinct_original_placeholders_get_sequential_canonical_ids(): void
    {
        $svc = app(OrchestratedLessonGenerationService::class);
        $scenes = [
            $this->slideWithImage('gen_img_1', 'First'),
            array_replace_recursive($this->slideWithImage('gen_img_9', 'Second'), [
                'id' => 's2',
                'content' => ['canvas' => ['elements' => [[
                    'type' => 'image',
                    'src' => 'gen_img_9',
                    'alt' => 'Second',
                ]]]],
            ]),
        ];

        $out = $this->invokeRenumber($svc, $scenes);

        $this->assertSame('gen_img_1', $out['scenes'][0]['content']['canvas']['elements'][0]['src']);
        $this->assertSame('gen_img_2', $out['scenes'][1]['content']['canvas']['elements'][0]['src']);
        $this->assertSame(['gen_img_1' => 'First', 'gen_img_2' => 'Second'], $out['altMap']);
    }

    #[Test]
    public function case_insensitive_matching_deduplicates_gen_img_placeholders(): void
    {
        $svc = app(OrchestratedLessonGenerationService::class);
        $scenes = [
            $this->slideWithImage('GEN_IMG_1'),
            array_replace_recursive($this->slideWithImage('gen_img_1'), [
                'id' => 's2',
            ]),
        ];

        $out = $this->invokeRenumber($svc, $scenes);

        $this->assertSame($out['scenes'][0]['content']['canvas']['elements'][0]['src'], $out['scenes'][1]['content']['canvas']['elements'][0]['src']);
        $this->assertCount(1, $out['altMap']);
    }

    #[Test]
    public function skips_quiz_scenes(): void
    {
        $svc = app(OrchestratedLessonGenerationService::class);
        $scenes = [
            [
                'type' => 'quiz',
                'id' => 'q1',
                'title' => 'Q',
                'content' => [
                    'type' => 'quiz',
                    'questions' => [],
                ],
            ],
        ];

        $out = $this->invokeRenumber($svc, $scenes);

        $this->assertSame($scenes, $out['scenes']);
        $this->assertSame([], $out['altMap']);
    }
}
