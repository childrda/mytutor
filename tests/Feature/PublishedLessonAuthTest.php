<?php

namespace Tests\Feature;

use App\Models\TutorLesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishedLessonAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_publish_snapshot(): void
    {
        $this->postJson('/api/published-lessons', [
            'stage' => ['id' => '01hxplaceholder000000000000', 'name' => 'A', 'language' => 'en'],
            'scenes' => [],
        ])->assertUnauthorized();
    }

    public function test_owner_can_publish_and_public_can_read_via_api_and_page(): void
    {
        $user = User::factory()->create();
        $lesson = TutorLesson::factory()->for($user)->create(['name' => 'Hi']);

        $this->actingAs($user)->postJson('/api/published-lessons', [
            'stage' => [
                'id' => $lesson->id,
                'name' => $lesson->name,
                'description' => '',
                'language' => 'en',
            ],
            'scenes' => [],
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('id', $lesson->id);

        $this->getJson('/api/published-lessons?id='.$lesson->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('lesson.id', $lesson->id);

        $this->get('/lesson/'.$lesson->id)->assertOk();
    }

    public function test_cannot_publish_someone_elses_lesson(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $lesson = TutorLesson::factory()->for($owner)->create();

        $this->actingAs($other)->postJson('/api/published-lessons', [
            'stage' => ['id' => $lesson->id, 'name' => 'x', 'language' => 'en'],
            'scenes' => [],
        ])->assertForbidden();
    }

    public function test_publish_requires_valid_stage_id(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/published-lessons', [
            'stage' => ['name' => 'No id', 'language' => 'en'],
            'scenes' => [],
        ])->assertStatus(400);
    }

    public function test_api_health_includes_request_id_header(): void
    {
        $res = $this->getJson('/api/health');
        $res->assertOk();
        $this->assertNotEmpty($res->headers->get('X-Request-Id'));
    }
}
