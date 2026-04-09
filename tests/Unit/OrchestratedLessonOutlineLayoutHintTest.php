<?php

namespace Tests\Unit;

use App\Services\LessonGeneration\OrchestratedLessonGenerationService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class OrchestratedLessonOutlineLayoutHintTest extends TestCase
{
    #[Test]
    public function normalize_outline_preserves_valid_layout_hint_for_slides(): void
    {
        $svc = app(OrchestratedLessonGenerationService::class);
        $m = (new ReflectionClass($svc))->getMethod('normalizeOutline');
        $m->setAccessible(true);
        $rows = [
            [
                'id' => 'a',
                'type' => 'slide',
                'title' => 'T',
                'order' => 0,
                'objective' => 'o',
                'notes' => '',
                'layoutHint' => 'three_cards',
            ],
        ];
        /** @var list<array<string, mixed>> $out */
        $out = $m->invoke($svc, $rows);

        $this->assertSame('three_cards', $out[0]['layoutHint'] ?? null);
    }

    #[Test]
    public function normalize_outline_defaults_invalid_hint_to_image_card(): void
    {
        $svc = app(OrchestratedLessonGenerationService::class);
        $m = (new ReflectionClass($svc))->getMethod('normalizeOutline');
        $m->setAccessible(true);
        $rows = [
            [
                'id' => 'a',
                'type' => 'slide',
                'title' => 'T',
                'order' => 0,
                'objective' => 'o',
                'notes' => '',
                'layoutHint' => 'not_a_real_hint',
            ],
        ];
        $out = $m->invoke($svc, $rows);

        $this->assertSame('image_card', $out[0]['layoutHint'] ?? null);
    }

    #[Test]
    public function normalize_outline_omits_layout_hint_for_quiz_rows(): void
    {
        $svc = app(OrchestratedLessonGenerationService::class);
        $m = (new ReflectionClass($svc))->getMethod('normalizeOutline');
        $m->setAccessible(true);
        $rows = [
            [
                'id' => 'q',
                'type' => 'quiz',
                'title' => 'Quiz',
                'order' => 0,
                'objective' => 'o',
                'notes' => '',
                'layoutHint' => 'three_cards',
            ],
        ];
        $out = $m->invoke($svc, $rows);

        $this->assertArrayNotHasKey('layoutHint', $out[0]);
    }
}
