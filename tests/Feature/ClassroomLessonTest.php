<?php

namespace Tests\Feature;

use App\Models\TutorLesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassroomLessonTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_redirected_from_classroom(): void
    {
        $user = User::factory()->create();
        $lesson = TutorLesson::factory()->for($user)->create();

        $this->get(route('classroom.lesson', ['lesson' => $lesson->id]))
            ->assertRedirect(route('login'));
    }

    public function test_non_owner_receives_not_found(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $lesson = TutorLesson::factory()->for($owner)->create();

        $this->actingAs($other)
            ->get(route('classroom.lesson', ['lesson' => $lesson->id]))
            ->assertNotFound();
    }

    public function test_owner_receives_classroom_page(): void
    {
        $user = User::factory()->create();
        $lesson = TutorLesson::factory()->for($user)->create(['name' => 'Bio 101']);

        $this->actingAs($user)
            ->get(route('classroom.lesson', ['lesson' => $lesson->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lessons/ClassroomShow')
                ->where('stage.name', 'Bio 101')
                ->has('scenes'));
    }
}
