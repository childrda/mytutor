<?php

namespace Tests\Feature;

use App\Models\TutorLesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TutorLessonApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_tutor_api(): void
    {
        $this->get('/tutor-api/lessons')->assertRedirect(route('login'));
    }

    public function test_user_can_crud_lesson_and_scenes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $create = $this->postJson('/tutor-api/lessons', [
            'name' => 'Algebra intro',
            'language' => 'en',
        ]);
        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('lesson.name', 'Algebra intro');

        $id = $create->json('lesson.id');
        $this->assertNotEmpty($id);

        $this->getJson("/tutor-api/lessons/{$id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('stage.name', 'Algebra intro')
            ->assertJsonPath('scenes', []);

        $scene = $this->postJson("/tutor-api/lessons/{$id}/scenes", [
            'type' => 'slide',
            'title' => 'Welcome',
            'content' => ['type' => 'slide', 'canvas' => ['title' => 'Hi']],
        ]);
        $scene->assertCreated()
            ->assertJsonPath('scene.title', 'Welcome')
            ->assertJsonPath('scene.type', 'slide');

        $sceneId = $scene->json('scene.id');

        $this->getJson("/tutor-api/lessons/{$id}")
            ->assertOk()
            ->assertJsonCount(1, 'scenes');

        $this->patchJson("/tutor-api/lessons/{$id}/scenes/{$sceneId}", [
            'title' => 'Welcome back',
        ])->assertOk()
            ->assertJsonPath('scene.title', 'Welcome back');

        $this->postJson("/tutor-api/lessons/{$id}/scenes/reorder", [
            'sceneIds' => [$sceneId],
        ])->assertOk();

        $this->deleteJson("/tutor-api/lessons/{$id}/scenes/{$sceneId}")
            ->assertOk();

        $this->deleteJson("/tutor-api/lessons/{$id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('tutor_lessons', ['id' => $id]);
    }

    public function test_user_cannot_access_other_users_lesson(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $lesson = TutorLesson::factory()->for($owner)->create();

        $this->actingAs($other);
        $this->getJson("/tutor-api/lessons/{$lesson->id}")->assertForbidden();
    }
}
