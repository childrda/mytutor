<?php

namespace App\Http\Controllers\Web;

use App\Http\Concerns\ResolvesPublicBaseUrl;
use App\Http\Controllers\Controller;
use App\Services\LessonGeneration\LessonGenerationService;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Session-aware entry point so generation jobs are attributed to the logged-in user.
 */
class LessonGenerationWebController extends Controller
{
    use ResolvesPublicBaseUrl;

    public function __construct(
        private readonly LessonGenerationService $generation,
    ) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $raw = $request->isJson() ? $request->json()->all() : $request->all();
            $job = $this->generation->createJob($raw, $request->user()->id);
            $pollUrl = $this->publicBaseUrl($request).'/api/generate-lesson/'.$job->id;

            return ApiJson::success([
                'jobId' => $job->id,
                'status' => $job->status,
                'phase' => $job->phase,
                'progress' => $job->progress,
                'pollUrl' => $pollUrl,
                'previewPath' => '/generation/'.$job->id,
                'pollIntervalMs' => 3000,
            ], 202);
        } catch (\InvalidArgumentException $e) {
            return ApiJson::error(ApiJson::MISSING_REQUIRED_FIELD, 400, $e->getMessage());
        } catch (Throwable $e) {
            return ApiJson::error(
                ApiJson::INTERNAL_ERROR,
                500,
                'Failed to create generation job',
                $e->getMessage(),
            );
        }
    }
}
