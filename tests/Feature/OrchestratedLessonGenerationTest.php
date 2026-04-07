<?php

namespace Tests\Feature;

use App\Jobs\ProcessLessonGenerationJob;
use App\Models\LessonGenerationJob;
use App\Models\User;
use App\Services\LessonGeneration\OrchestratedLessonGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrchestratedLessonGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_completes_with_three_mocked_llm_round_trips(): void
    {
        Config::set('tutor.default_chat.api_key', 'sk-test-fake');
        Config::set('tutor.lesson_generation.stream_outline', false);
        Config::set('tutor.lesson_generation.slide_visual_fallback_wikimedia', false);
        Config::set('tutor.lesson_generation.content_scene_max_concurrent', 1);
        Config::set('tutor.lesson_generation.actions_scene_max_concurrent', 1);

        $r1 = json_encode([
            'stage' => ['id' => '', 'name' => 'Pipe Test', 'description' => 'D', 'language' => 'en'],
            'classroomRoles' => [
                'version' => 1,
                'personas' => [
                    ['id' => 't1', 'role' => 'teacher', 'name' => 'Dr. T', 'bio' => 'Teacher', 'accentColor' => '#4F46E5'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $r2 = json_encode([
            'outline' => [
                ['id' => 'sc1', 'type' => 'slide', 'title' => 'Intro', 'order' => 0, 'objective' => 'o', 'notes' => ''],
                ['id' => 'sc2', 'type' => 'slide', 'title' => 'More', 'order' => 1, 'objective' => 'o2', 'notes' => ''],
            ],
        ], JSON_THROW_ON_ERROR);

        $r3 = json_encode([
            'scenes' => [
                [
                    'id' => 'sc1',
                    'type' => 'slide',
                    'title' => 'Intro',
                    'order' => 0,
                    'content' => [
                        'type' => 'slide',
                        'canvas' => [
                            'title' => 'Intro',
                            'width' => 1000,
                            'height' => 562.5,
                            'elements' => [
                                ['type' => 'text', 'id' => 'e1', 'x' => 48, 'y' => 48, 'width' => 900, 'height' => 80, 'fontSize' => 24, 'text' => 'Hello'],
                                ['type' => 'text', 'id' => 'e2', 'x' => 48, 'y' => 140, 'width' => 900, 'height' => 200, 'fontSize' => 20, 'text' => 'World'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'sc2',
                    'type' => 'slide',
                    'title' => 'More',
                    'order' => 1,
                    'content' => [
                        'type' => 'slide',
                        'canvas' => [
                            'title' => 'More',
                            'width' => 1000,
                            'height' => 562.5,
                            'elements' => [
                                ['type' => 'text', 'id' => 'e3', 'x' => 48, 'y' => 48, 'width' => 900, 'height' => 80, 'fontSize' => 24, 'text' => 'A'],
                                ['type' => 'text', 'id' => 'e4', 'x' => 48, 'y' => 140, 'width' => 900, 'height' => 200, 'fontSize' => 20, 'text' => 'B'],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $r3Decoded = json_decode($r3, true, 512, JSON_THROW_ON_ERROR);
        $r3PerSceneA = json_encode(['scene' => $r3Decoded['scenes'][0]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $r3PerSceneB = json_encode(['scene' => $r3Decoded['scenes'][1]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $rAct1 = json_encode([
            'actions' => [
                ['type' => 'speech', 'label' => 'Intro', 'text' => 'LLM voiceover step: welcome to this slide with teaching detail.'],
                ['type' => 'spotlight', 'label' => 'Focus', 'target' => ['elementId' => 'e1'], 'durationMs' => 3800],
                ['type' => 'speech', 'label' => 'Explain', 'text' => 'Here we connect the ideas for learners.'],
                ['type' => 'interact', 'label' => 'Partner', 'mode' => 'pause', 'prompt' => 'Turn to a partner: one takeaway?'],
            ],
        ], JSON_THROW_ON_ERROR);
        $rAct2 = json_encode([
            'actions' => [
                ['type' => 'speech', 'label' => 'Open', 'text' => 'Second scene LLM voiceover continues the lesson.'],
                ['type' => 'spotlight', 'label' => 'Look', 'target' => ['elementId' => 'e3'], 'durationMs' => 3800],
            ],
        ], JSON_THROW_ON_ERROR);

        Http::fake(function () use ($r1, $r2, $r3PerSceneA, $r3PerSceneB, $rAct1, $rAct2) {
            static $n = 0;
            $bodies = [$r1, $r2, $r3PerSceneA, $r3PerSceneB, $rAct1, $rAct2];
            $body = $bodies[$n] ?? $rAct2;
            $n++;

            return Http::response([
                'choices' => [
                    ['message' => ['content' => $body]],
                ],
            ], 200);
        });

        $user = User::factory()->create();
        $job = LessonGenerationJob::query()->create([
            'user_id' => $user->id,
            'status' => 'queued',
            'phase' => 'queued',
            'progress' => 0,
            'request' => ['requirement' => 'Test pipeline integration', 'language' => 'en'],
        ]);

        (new ProcessLessonGenerationJob($job->id))->handle(app(OrchestratedLessonGenerationService::class));

        $this->assertCount(6, Http::recorded(), 'Expected roles + outline + 2× content + 2× actions LLM calls.');

        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertSame('completed', $job->phase);
        $result = $job->result;
        $this->assertIsArray($result);
        $this->assertCount(2, $result['scenes'] ?? []);
        $this->assertArrayHasKey('outline', $result);
        $this->assertSame('Dr. T', $result['classroomRoles']['personas'][0]['name'] ?? null);
        $firstActions = $result['scenes'][0]['actions'] ?? [];
        $this->assertNotEmpty($firstActions);
        $this->assertSame('speech', $firstActions[0]['type'] ?? null);
        $this->assertStringContainsString('LLM voiceover', (string) ($firstActions[0]['text'] ?? ''));
        $types = array_column($firstActions, 'type');
        $this->assertContains('spotlight', $types);
        $this->assertContains('interact', $types);
    }

    public function test_pipeline_marks_failed_step_on_invalid_json(): void
    {
        Config::set('tutor.default_chat.api_key', 'sk-test-fake');
        Config::set('tutor.lesson_generation.stream_outline', false);

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'NOT JSON']]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $job = LessonGenerationJob::query()->create([
            'user_id' => $user->id,
            'status' => 'queued',
            'phase' => 'queued',
            'progress' => 0,
            'request' => ['requirement' => 'x', 'language' => 'en'],
        ]);

        (new ProcessLessonGenerationJob($job->id))->handle(app(OrchestratedLessonGenerationService::class));

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame('failed', $job->phase);
        $this->assertSame('roles', $job->result['pipelineFailedStep'] ?? null);
    }
}
