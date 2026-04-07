<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesPublicBaseUrl;
use App\Http\Controllers\Controller;
use App\Models\VideoGenerationJob;
use App\Services\MediaGeneration\VideoGenerationService;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class VideoGenerationController extends Controller
{
    use ResolvesPublicBaseUrl;

    public function __construct(
        private readonly VideoGenerationService $videoGeneration,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $body = $request->all();
        $clientKey = isset($body['apiKey']) && is_string($body['apiKey']) ? trim($body['apiKey']) : '';

        $requiresKey = ($body['requiresApiKey'] ?? true) !== false;
        $serverKey = (string) config('tutor.video_generation.api_key');
        if ($requiresKey && $clientKey === '' && $serverKey === '') {
            return ApiJson::error(ApiJson::MISSING_API_KEY, 401, 'API key is required');
        }

        $overrideKey = $clientKey !== '' ? $clientKey : null;

        try {
            [$job, $created] = $this->videoGeneration->createJob($body, $request->user()?->id, $overrideKey);
        } catch (\InvalidArgumentException $e) {
            return ApiJson::error(ApiJson::MISSING_REQUIRED_FIELD, 400, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return ApiJson::error(
                ApiJson::INTERNAL_ERROR,
                500,
                'Failed to create video generation job',
                $e->getMessage(),
            );
        }

        $pollUrl = $this->publicBaseUrl($request).'/api/generate/video/'.$job->id;
        $interval = max(500, (int) config('tutor.video_generation.poll_interval_ms', 5000));

        return ApiJson::success([
            'jobId' => $job->id,
            'status' => $job->status,
            'pollUrl' => $pollUrl,
            'pollIntervalMs' => $interval,
            'result' => $job->result,
            'error' => $job->error,
        ], $created ? 202 : 200);
    }

    public function show(string $jobId): JsonResponse
    {
        if (! Str::isUlid($jobId)) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Job not found');
        }

        $job = VideoGenerationJob::query()->find($jobId);
        if (! $job) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Job not found');
        }

        return ApiJson::success([
            'jobId' => $job->id,
            'status' => $job->status,
            'result' => $job->result,
            'error' => $job->error,
            'updatedAt' => $job->updated_at?->toIso8601String(),
        ]);
    }
}
