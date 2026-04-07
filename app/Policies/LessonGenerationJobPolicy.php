<?php

namespace App\Policies;

use App\Models\LessonGenerationJob;
use App\Models\User;

class LessonGenerationJobPolicy
{
    public function view(User $user, LessonGenerationJob $job): bool
    {
        return $job->user_id !== null && (int) $job->user_id === (int) $user->id;
    }
}
