<?php

namespace App\Models;

use Database\Factories\TutorLessonFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TutorLesson extends Model
{
    /** @use HasFactory<TutorLessonFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'language',
        'style',
        'current_scene_id',
        'agent_ids',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'agent_ids' => 'array',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(TutorScene::class)->orderBy('scene_order');
    }
}
