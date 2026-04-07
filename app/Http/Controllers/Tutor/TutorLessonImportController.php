<?php

namespace App\Http\Controllers\Tutor;

use App\Http\Controllers\Controller;
use App\Http\Resources\TutorLessonResource;
use App\Models\LessonGenerationJob;
use App\Services\LessonGeneration\LessonImportService;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class TutorLessonImportController extends Controller
{
    public function __construct(
        private readonly LessonImportService $import,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'jobId' => ['required', 'string', 'max:64'],
        ]);

        $job = LessonGenerationJob::query()->find($data['jobId']);
        if (! $job) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Job not found');
        }

        if ($job->user_id !== $request->user()->id) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 403, 'You can only import your own generation jobs');
        }

        try {
            $out = $this->import->importFromJob($request->user(), $job);
        } catch (InvalidArgumentException $e) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, $e->getMessage());
        }

        return ApiJson::success([
            'lesson' => new TutorLessonResource($out['lesson']->load(['scenes' => fn ($q) => $q->orderBy('scene_order')])),
            'studioUrl' => route('studio.lesson', ['lesson' => $out['lesson']->id]),
        ], 201);
    }
}
