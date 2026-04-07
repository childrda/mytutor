<?php

namespace Tests\Feature;

use App\Models\LessonGenerationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonGenerationAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_create_generation_job_via_api(): void
    {
        $this->postJson('/api/generate-lesson', [
            'requirement' => 'Intro to algebra',
            'language' => 'en',
        ])->assertUnauthorized();
    }

    public function test_guest_cannot_use_tutor_web_generate_lesson(): void
    {
        $this->postJson('/tutor-api/generate-lesson', [
            'requirement' => 'Intro to algebra',
            'language' => 'en',
        ])->assertUnauthorized();
    }

    public function test_guest_cannot_poll_generation_job(): void
    {
        $user = User::factory()->create();
        $job = LessonGenerationJob::query()->create([
            'user_id' => $user->id,
            'status' => 'queued',
            'request' => ['requirement' => 'x', 'language' => 'en'],
        ]);

        $this->getJson('/api/generate-lesson/'.$job->id)->assertUnauthorized();
    }

    public function test_authenticated_user_can_poll_own_job(): void
    {
        $user = User::factory()->create();
        $job = LessonGenerationJob::query()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'phase' => 'completed',
            'progress' => 100,
            'request' => ['requirement' => 'x', 'language' => 'en'],
            'result' => ['stage' => ['name' => 'N'], 'scenes' => []],
        ]);

        $this->actingAs($user)
            ->getJson('/api/generate-lesson/'.$job->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('jobId', $job->id)
            ->assertJsonPath('phase', 'completed')
            ->assertJsonPath('progress', 100);
    }

    public function test_user_cannot_poll_another_users_job(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $job = LessonGenerationJob::query()->create([
            'user_id' => $owner->id,
            'status' => 'queued',
            'request' => ['requirement' => 'x', 'language' => 'en'],
        ]);

        $this->actingAs($other)
            ->getJson('/api/generate-lesson/'.$job->id)
            ->assertForbidden();
    }

    public function test_user_cannot_poll_legacy_job_with_null_owner(): void
    {
        $user = User::factory()->create();
        $job = LessonGenerationJob::query()->create([
            'user_id' => null,
            'status' => 'queued',
            'request' => ['requirement' => 'x', 'language' => 'en'],
        ]);

        $this->actingAs($user)
            ->getJson('/api/generate-lesson/'.$job->id)
            ->assertForbidden();
    }
}
