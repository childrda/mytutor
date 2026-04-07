<?php

namespace App\Policies;

use App\Models\TutorLesson;
use App\Models\User;

class TutorLessonPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TutorLesson $lesson): bool
    {
        return $lesson->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TutorLesson $lesson): bool
    {
        return $lesson->user_id === $user->id;
    }

    public function delete(User $user, TutorLesson $lesson): bool
    {
        return $lesson->user_id === $user->id;
    }
}
