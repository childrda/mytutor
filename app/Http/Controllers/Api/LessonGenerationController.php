<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesPublicBaseUrl;
use App\Http\Controllers\Controller;
use App\Http\Resources\TutorSceneResource;
use App\Models\LessonGenerationJob;
use App\Services\LessonGeneration\LessonGenerationService;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class LessonGenerationController extends Controller
{
    use ResolvesPublicBaseUrl;

    public function __construct(
        private readonly LessonGenerationService $generation,
    ) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $job = $this->generation->createJob($request->json()->all(), $request->user()->id);

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

    public function show(Request $request, string $jobId): JsonResponse
    {
        $job = LessonGenerationJob::query()->find($jobId);
        if (! $job) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Job not found');
        }

        $this->authorize('view', $job);

        $classroomRoles = $job->classroom_roles;
        if ($classroomRoles === null && is_array($job->result)) {
            $classroomRoles = $job->result['classroomRoles'] ?? null;
        }

        $result = $job->result;
        if (is_array($result) && isset($result['scenes']) && is_array($result['scenes'])) {
            $result['scenes'] = TutorSceneResource::normalizeScenesListStorageUrls($result['scenes']);
        }

        return ApiJson::success([
            'jobId' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
            'progress' => $job->progress,
            'phaseDetail' => $job->phase_detail,
            'classroomRoles' => $classroomRoles,
            'result' => $result,
            'error' => $job->error,
            'updatedAt' => $job->updated_at?->toIso8601String(),
        ]);
    }
}
