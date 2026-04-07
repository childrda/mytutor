<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TutorScene extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'tutor_lesson_id',
        'type',
        'title',
        'scene_order',
        'content',
        'actions',
        'whiteboard',
        'multi_agent',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'actions' => 'array',
            'whiteboard' => 'array',
            'multi_agent' => 'array',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(TutorLesson::class, 'tutor_lesson_id');
    }
}
