<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublishedLesson extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'document',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'document' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
