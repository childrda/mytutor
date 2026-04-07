<?php

namespace Tests\Feature;

use App\Models\LessonGenerationJob;
use App\Models\TutorLesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase22HomeAndImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_web_generate_lesson_sets_user_id_on_job(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/tutor-api/generate-lesson', [
            'requirement' => 'Intro to fractions',
        ]);

        $response->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('jobId', fn ($id) => is_string($id) && $id !== '');

        $jobId = $response->json('jobId');
        $this->assertSame($user->id, LessonGenerationJob::query()->find($jobId)?->user_id);
    }

    public function test_import_from_completed_job_creates_lesson_and_returns_studio_url(): void
    {
        $user = User::factory()->create();
        $job = LessonGenerationJob::query()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'request' => ['requirement' => 'x', 'language' => 'en'],
            'result' => [
                'stage' => [
                    'name' => 'Imported title',
                    'description' => 'Desc',
                    'language' => 'en',
                ],
                'scenes' => [
                    [
                        'type' => 'slide',
                        'title' => 'Slide one',
                        'order' => 0,
                        'content' => ['type' => 'slide', 'canvas' => ['title' => 'Hi']],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->postJson('/tutor-api/lessons/import-from-job', [
            'jobId' => $job->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('lesson.name', 'Imported title')
            ->assertJsonPath('studioUrl', fn ($url) => is_string($url) && str_contains($url, '/studio/'));

        $lessonId = $response->json('lesson.id');
        $this->assertDatabaseHas('tutor_lessons', [
            'id' => $lessonId,
            'user_id' => $user->id,
            'name' => 'Imported title',
        ]);
        $this->assertDatabaseCount('tutor_scenes', 1);
    }

    public function test_import_from_job_forbidden_for_other_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $job = LessonGenerationJob::query()->create([
            'user_id' => $owner->id,
            'status' => 'completed',
            'request' => ['requirement' => 'x', 'language' => 'en'],
            'result' => [
                'stage' => ['name' => 'X'],
                'scenes' => [],
            ],
        ]);

        $this->actingAs($other)->postJson('/tutor-api/lessons/import-from-job', [
            'jobId' => $job->id,
        ])->assertForbidden();
    }

    public function test_studio_lesson_page_renders_for_owner(): void
    {
        $user = User::factory()->create();
        $lesson = TutorLesson::factory()->for($user)->create(['name' => 'My stage']);

        $this->actingAs($user)
            ->get(route('studio.lesson', ['lesson' => $lesson->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lessons/StudioShow')
                ->where('stage.name', 'My stage')
                ->has('scenes'));
    }
}
