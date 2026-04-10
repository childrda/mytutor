<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global (app-wide) active models.json registry keys saved from Settings.
 * Effective value: non-empty {@see config('tutor.active.*')} (from env) wins over this table.
 */
class TutorRegistryActive extends Model
{
    protected $fillable = [
        'capability',
        'active_key',
    ];
}
