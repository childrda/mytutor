<?php

namespace App\Services\Settings;

use App\Models\TutorRegistryActive;
use App\Services\Ai\ModelRegistry;
use App\Services\Ai\TutorActiveRegistrySelection;
use Illuminate\Support\Arr;

/**
 * Read/update global active models.json keys (database layer; env still wins via {@see TutorActiveRegistrySelection}).
 */
final class TutorRegistryActiveSettings
{
    /**
     * @return array{
     *   capabilities: list<string>,
     *   providerKeys: array<string, list<string>>,
     *   database: array<string, string|null>,
     *   configLayer: array<string, string|null>,
     *   effective: array<string, string|null>
     * }
     */
    public function snapshot(): array
    {
        $registry = app(ModelRegistry::class);
        $selection = app(TutorActiveRegistrySelection::class);

        $capabilities = ModelRegistry::CAPABILITIES;
        $providerKeys = [];
        $database = [];
        $configLayer = [];
        $effective = [];

        foreach ($capabilities as $cap) {
            $providerKeys[$cap] = $registry->providerKeys($cap);
            $database[$cap] = TutorRegistryActive::query()->where('capability', $cap)->value('active_key');
            $raw = config("tutor.active.{$cap}");
            $configLayer[$cap] = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
            $effective[$cap] = $selection->resolve($cap);
        }

        return [
            'capabilities' => $capabilities,
            'providerKeys' => $providerKeys,
            'database' => $database,
            'configLayer' => $configLayer,
            'effective' => $effective,
        ];
    }

    /**
     * @param  array<string, mixed>  $active  capability => key|null|''
     *
     * @throws \InvalidArgumentException when a key is unknown for its capability
     */
    public function save(array $active): void
    {
        $registry = app(ModelRegistry::class);
        $active = Arr::only($active, ModelRegistry::CAPABILITIES);

        foreach ($active as $cap => $key) {
            if ($key !== null && $key !== '' && ! $registry->has($cap, (string) $key)) {
                throw new \InvalidArgumentException(
                    'Unknown model registry id "'.(string) $key.'" for capability "'.$cap.'".',
                );
            }
        }

        foreach ($active as $cap => $key) {
            if ($key === null || $key === '') {
                TutorRegistryActive::query()->where('capability', $cap)->delete();
            } else {
                TutorRegistryActive::query()->updateOrCreate(
                    ['capability' => $cap],
                    ['active_key' => trim((string) $key)],
                );
            }
        }
    }
}
