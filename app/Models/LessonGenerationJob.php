<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonGenerationJob extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'status',
        'phase',
        'progress',
        'phase_detail',
        'classroom_roles',
        'request',
        'result',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'result' => 'array',
            'phase_detail' => 'array',
            'classroom_roles' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
