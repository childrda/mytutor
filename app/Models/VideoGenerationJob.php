<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoGenerationJob extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'client_job_id',
        'status',
        'request',
        'result',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'result' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
