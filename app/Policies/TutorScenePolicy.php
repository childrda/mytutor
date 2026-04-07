<?php

namespace App\Policies;

use App\Models\TutorLesson;
use App\Models\TutorScene;
use App\Models\User;

class TutorScenePolicy
{
    public function create(User $user, TutorLesson $lesson): bool
    {
        return $lesson->user_id === $user->id;
    }

    public function update(User $user, TutorScene $scene): bool
    {
        $lesson = $scene->lesson;

        return $lesson && $lesson->user_id === $user->id;
    }

    public function delete(User $user, TutorScene $scene): bool
    {
        return $this->update($user, $scene);
    }

}
