<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LlmExchangeLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'lesson_generation_job_id',
        'direction',
        'source',
        'correlation_id',
        'endpoint',
        'http_status',
        'payload',
        'meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
