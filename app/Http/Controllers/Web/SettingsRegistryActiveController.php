<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Ai\ModelRegistry;
use App\Services\Settings\TutorRegistryActiveSettings;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Throwable;

/**
 * Global active models.json registry keys (Phase B4). Auth required; values are app-wide, not per user.
 */
final class SettingsRegistryActiveController extends Controller
{
    public function __construct(
        private readonly TutorRegistryActiveSettings $activeSettings,
    ) {}

    public function show(): JsonResponse
    {
        return ApiJson::success($this->activeSettings->snapshot());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'active' => ['required', 'array'],
            'active.*' => ['nullable', 'string', 'max:128'],
        ]);

        $active = Arr::only($validated['active'], ModelRegistry::CAPABILITIES);

        try {
            $this->activeSettings->save($active);
        } catch (InvalidArgumentException $e) {
            return ApiJson::error(
                ApiJson::INVALID_REQUEST,
                422,
                $e->getMessage(),
            );
        } catch (Throwable $e) {
            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, $e->getMessage());
        }

        return ApiJson::success(['saved' => true]);
    }
}
