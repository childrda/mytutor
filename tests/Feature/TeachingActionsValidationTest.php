<?php

namespace Tests\Feature;

use App\Models\TutorLesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeachingActionsValidationTest extends TestCase
{
    use RefreshDatabase;

    private function lessonWithSlideScene(User $user): array
    {
        $lesson = TutorLesson::factory()->for($user)->create([
            'meta' => [
                'classroomRoles' => [
                    'version' => 1,
                    'personas' => [
                        ['id' => 'person-1', 'role' => 'teacher', 'name' => 'Dr. T', 'bio' => 'Bio'],
                    ],
                ],
            ],
        ]);

        $scene = $lesson->scenes()->create([
            'type' => 'slide',
            'title' => 'Intro',
            'scene_order' => 0,
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'Intro',
                    'width' => 1000,
                    'height' => 562.5,
                    'elements' => [
                        ['type' => 'text', 'id' => 'elem-a', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 40, 'fontSize' => 20, 'text' => 'Hello'],
                    ],
                ],
            ],
            'actions' => null,
        ]);

        return [$lesson, $scene];
    }

    public function test_rejects_spotlight_with_unknown_element_id(): void
    {
        $user = User::factory()->create();
        [$lesson, $scene] = $this->lessonWithSlideScene($user);

        $this->actingAs($user)
            ->patchJson("/tutor-api/lessons/{$lesson->id}/scenes/{$scene->id}", [
                'actions' => [
                    [
                        'id' => 'a1',
                        'type' => 'spotlight',
                        'label' => 'X',
                        'target' => ['kind' => 'element', 'elementId' => 'not-there'],
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['actions']);
    }

    public function test_accepts_valid_teaching_actions(): void
    {
        $user = User::factory()->create();
        [$lesson, $scene] = $this->lessonWithSlideScene($user);

        $this->actingAs($user)
            ->patchJson("/tutor-api/lessons/{$lesson->id}/scenes/{$scene->id}", [
                'actions' => [
                    [
                        'id' => 's1',
                        'type' => 'speech',
                        'label' => 'Hello',
                        'text' => 'Welcome.',
                        'personaId' => 'person-1',
                    ],
                    [
                        'id' => 'sp1',
                        'type' => 'spotlight',
                        'label' => 'Focus',
                        'target' => ['kind' => 'element', 'elementId' => 'elem-a'],
                        'durationMs' => 3000,
                    ],
                    [
                        'id' => 'i1',
                        'type' => 'interact',
                        'label' => 'Pause',
                        'mode' => 'pause',
                        'prompt' => 'Discuss.',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('scene.actions.0.type', 'speech')
            ->assertJsonPath('scene.actions.1.type', 'spotlight')
            ->assertJsonPath('scene.actions.2.type', 'interact');
    }

    public function test_rejects_bad_persona_on_speech(): void
    {
        $user = User::factory()->create();
        [$lesson, $scene] = $this->lessonWithSlideScene($user);

        $this->actingAs($user)
            ->patchJson("/tutor-api/lessons/{$lesson->id}/scenes/{$scene->id}", [
                'actions' => [
                    [
                        'id' => 's1',
                        'type' => 'speech',
                        'text' => 'Hi',
                        'personaId' => 'ghost',
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['actions']);
    }
}
