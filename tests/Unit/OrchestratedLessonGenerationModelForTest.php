<?php

namespace Tests\Unit;

use App\Services\LessonGeneration\OrchestratedLessonGenerationService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class OrchestratedLessonGenerationModelForTest extends TestCase
{
    private function invokeModelFor(OrchestratedLessonGenerationService $svc, string $step): string
    {
        $m = new ReflectionMethod(OrchestratedLessonGenerationService::class, 'modelFor');
        $m->setAccessible(true);

        return (string) $m->invoke($svc, $step);
    }

    public function test_generic_openai_registry_row_uses_default_chat_model_over_template_default(): void
    {
        config([
            'tutor.lesson_generation.roles_model' => null,
            'tutor.lesson_generation.outline_model' => null,
            'tutor.lesson_generation.content_model' => null,
            'tutor.lesson_generation.actions_model' => null,
            'tutor.active.llm' => 'openai',
            'tutor.default_chat.model' => 'gpt-5.4-mini',
        ]);

        $svc = app(OrchestratedLessonGenerationService::class);
        $this->assertSame('gpt-5.4-mini', $this->invokeModelFor($svc, 'content'));
    }

    public function test_generic_openai_when_default_chat_empty_uses_template_default(): void
    {
        config([
            'tutor.lesson_generation.content_model' => null,
            'tutor.active.llm' => 'openai',
            'tutor.default_chat.model' => '',
        ]);

        $svc = app(OrchestratedLessonGenerationService::class);
        $this->assertSame('gpt-4o-mini', $this->invokeModelFor($svc, 'content'));
    }

    public function test_concrete_registry_row_model_wins_over_default_chat(): void
    {
        config([
            'tutor.lesson_generation.content_model' => null,
            'tutor.active.llm' => 'openai-gpt-4o',
            'tutor.default_chat.model' => 'gpt-5.4-mini',
        ]);

        $svc = app(OrchestratedLessonGenerationService::class);
        $this->assertSame('gpt-4o', $this->invokeModelFor($svc, 'content'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function perStepOverrideProvider(): array
    {
        return [
            'roles' => ['roles', 'gpt-custom-roles'],
            'outline' => ['outline', 'gpt-custom-outline'],
            'content' => ['content', 'gpt-custom-content'],
            'actions' => ['actions', 'gpt-custom-actions'],
        ];
    }

    #[DataProvider('perStepOverrideProvider')]
    public function test_per_step_config_override_wins(string $step, string $expected): void
    {
        config([
            'tutor.lesson_generation.roles_model' => null,
            'tutor.lesson_generation.outline_model' => null,
            'tutor.lesson_generation.content_model' => null,
            'tutor.lesson_generation.actions_model' => null,
            'tutor.active.llm' => 'openai',
            'tutor.default_chat.model' => 'gpt-4o-mini',
        ]);
        config(["tutor.lesson_generation.{$step}_model" => $expected]);

        $svc = app(OrchestratedLessonGenerationService::class);
        $this->assertSame($expected, $this->invokeModelFor($svc, $step));
    }
}
