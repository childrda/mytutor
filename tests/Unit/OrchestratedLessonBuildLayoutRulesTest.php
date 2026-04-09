<?php

namespace Tests\Unit;

use App\Services\LessonGeneration\OrchestratedLessonGenerationService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class OrchestratedLessonBuildLayoutRulesTest extends TestCase
{
    private function invokeBuildLayoutRules(
        OrchestratedLessonGenerationService $svc,
        array $pdfPageImages,
        array $spec,
    ): string {
        $m = (new ReflectionClass($svc))->getMethod('buildLayoutRules');
        $m->setAccessible(true);

        return $m->invoke($svc, $pdfPageImages, $spec);
    }

    #[Test]
    public function no_pdf_three_cards_forbids_image_and_describes_three_columns(): void
    {
        $svc = app(OrchestratedLessonGenerationService::class);
        $s = $this->invokeBuildLayoutRules($svc, [], [
            'type' => 'slide',
            'layoutHint' => 'three_cards',
        ]);

        $this->assertStringContainsString('NO type "image"', $s);
        $this->assertStringContainsString('three_cards', $s);
        $this->assertStringContainsString('32', $s);
    }

    #[Test]
    public function no_pdf_image_card_requires_gen_img_and_key_ideas_card(): void
    {
        $svc = app(OrchestratedLessonGenerationService::class);
        $s = $this->invokeBuildLayoutRules($svc, [], [
            'type' => 'slide',
            'layoutHint' => 'image_card',
        ]);

        $this->assertStringContainsString('gen_img', $s);
        $this->assertStringContainsString('Key ideas', $s);
        $this->assertStringContainsString('image_card', $s);
    }

    #[Test]
    public function no_pdf_quiz_spec_returns_quiz_only_message(): void
    {
        $svc = app(OrchestratedLessonGenerationService::class);
        $s = $this->invokeBuildLayoutRules($svc, [], [
            'type' => 'quiz',
            'layoutHint' => 'image_card',
        ]);

        $this->assertStringContainsString('quiz', $s);
        $this->assertStringNotContainsString('LAYOUT image_card', $s);
    }

    #[Test]
    public function pdf_present_uses_vision_layout_not_slide_hint_match(): void
    {
        $svc = app(OrchestratedLessonGenerationService::class);
        $s = $this->invokeBuildLayoutRules($svc, ['data:image/png;base64,abc'], [
            'type' => 'slide',
            'layoutHint' => 'three_cards',
        ]);

        $this->assertStringContainsString('pdf_page', $s);
        $this->assertStringNotContainsString('LAYOUT three_cards', $s);
    }
}
