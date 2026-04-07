<?php

namespace Database\Factories;

use App\Models\TutorLesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TutorLesson>
 */
class TutorLessonFactory extends Factory
{
    protected $model = TutorLesson::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'language' => 'en',
            'style' => null,
            'current_scene_id' => null,
            'agent_ids' => null,
            'meta' => null,
        ];
    }
}
