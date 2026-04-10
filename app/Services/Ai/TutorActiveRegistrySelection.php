<?php

namespace App\Services\Ai;

use App\Models\TutorRegistryActive;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves which models.json model id is active per capability (Phase B4).
 * Call only with a capability from {@see ModelRegistry::CAPABILITIES} (validated by {@see ModelRegistry::activeKey()}).
 *
 * Order: non-empty {@see config('tutor.active.{capability}')} (typically from TUTOR_ACTIVE_* env) wins;
 * otherwise the value from {@see TutorRegistryActive} (Settings UI). Empty/null means legacy (no registry routing).
 */
final class TutorActiveRegistrySelection
{
    public function resolve(string $capability): ?string
    {
        $raw = config("tutor.active.{$capability}");
        if (is_string($raw) && trim($raw) !== '') {
            return trim($raw);
        }

        try {
            if (! Schema::hasTable('tutor_registry_actives')) {
                return null;
            }

            $stored = TutorRegistryActive::query()->where('capability', $capability)->value('active_key');
        } catch (QueryException) {
            return null;
        }

        if (! is_string($stored) || trim($stored) === '') {
            return null;
        }

        return trim($stored);
    }
}
