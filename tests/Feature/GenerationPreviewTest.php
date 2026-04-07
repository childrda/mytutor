<?php

namespace Tests\Feature;

use App\Models\LessonGenerationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_generation_preview(): void
    {
        $user = User::factory()->create();
        $job = LessonGenerationJob::query()->create([
            'user_id' => $user->id,
            'status' => 'queued',
            'request' => ['requirement' => 'x', 'language' => 'en'],
        ]);

        $this->get(route('generation.preview', ['job' => $job->id]))
            ->assertRedirect();
    }

    public function test_owner_receives_generation_preview_inertia_page(): void
    {
        $user = User::factory()->create();
        $job = LessonGenerationJob::query()->create([
            'user_id' => $user->id,
            'status' => 'queued',
            'phase' => 'queued',
            'progress' => 0,
            'request' => ['requirement' => 'x', 'language' => 'en'],
        ]);

        $this->actingAs($user)
            ->get(route('generation.preview', ['job' => $job->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('GenerationPreview')
                ->where('jobId', $job->id)
                ->has('pipelineSteps'));
    }

    public function test_non_owner_forbidden_from_generation_preview(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $job = LessonGenerationJob::query()->create([
            'user_id' => $owner->id,
            'status' => 'queued',
            'request' => ['requirement' => 'x', 'language' => 'en'],
        ]);

        $this->actingAs($other)
            ->get(route('generation.preview', ['job' => $job->id]))
            ->assertForbidden();
    }

    public function test_poll_includes_phase_and_progress(): void
    {
        $user = User::factory()->create();
        $job = LessonGenerationJob::query()->create([
            'user_id' => $user->id,
            'status' => 'running',
            'phase' => 'course_outline',
            'progress' => 22,
            'request' => ['requirement' => 'x', 'language' => 'en'],
        ]);

        $this->actingAs($user)
            ->getJson('/api/generate-lesson/'.$job->id)
            ->assertOk()
            ->assertJsonPath('phase', 'course_outline')
            ->assertJsonPath('progress', 22);
    }

    public function test_poll_includes_classroom_roles_from_column(): void
    {
        $user = User::factory()->create();
        $roles = [
            'version' => 1,
            'personas' => [
                ['id' => '01', 'role' => 'teacher', 'name' => 'T', 'bio' => 'Guides.'],
            ],
        ];
        $job = LessonGenerationJob::query()->create([
            'user_id' => $user->id,
            'status' => 'running',
            'phase' => 'page_content',
            'progress' => 60,
            'classroom_roles' => $roles,
            'request' => ['requirement' => 'x', 'language' => 'en'],
        ]);

        $this->actingAs($user)
            ->getJson('/api/generate-lesson/'.$job->id)
            ->assertOk()
            ->assertJsonPath('classroomRoles.personas.0.name', 'T');
    }

    public function test_poll_includes_partial_result_outline_and_scenes_for_preview_ui(): void
    {
        $user = User::factory()->create();
        $outline = [
            ['id' => 'a', 'type' => 'slide', 'title' => 'One', 'order' => 0, 'objective' => 'Learn', 'notes' => ''],
        ];
        $scenes = [
            ['id' => 'a', 'type' => 'slide', 'title' => 'One', 'order' => 0, 'content' => ['type' => 'slide']],
        ];
        $job = LessonGenerationJob::query()->create([
            'user_id' => $user->id,
            'status' => 'running',
            'phase' => 'teaching_actions',
            'progress' => 80,
            'request' => ['requirement' => 'x', 'language' => 'en'],
            'result' => [
                'partial' => true,
                'outline' => $outline,
                'scenes' => $scenes,
            ],
        ]);

        $this->actingAs($user)
            ->getJson('/api/generate-lesson/'.$job->id)
            ->assertOk()
            ->assertJsonPath('result.outline.0.title', 'One')
            ->assertJsonPath('result.scenes.0.title', 'One');
    }
}
