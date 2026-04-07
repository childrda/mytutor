<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesPublicBaseUrl;
use App\Http\Controllers\Controller;
use App\Models\TutorLesson;
use App\Services\PublishedLesson\PublishedLessonStorage;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PublishedLessonController extends Controller
{
    use ResolvesPublicBaseUrl;

    public function __construct(
        private readonly PublishedLessonStorage $storage,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $stage = $payload['stage'] ?? null;
        $scenes = $payload['scenes'] ?? null;
        if (! is_array($stage) || ! is_array($scenes)) {
            return ApiJson::error(
                ApiJson::MISSING_REQUIRED_FIELD,
                400,
                'Missing required fields: stage, scenes',
            );
        }

        $lessonId = $stage['id'] ?? null;
        if (! is_string($lessonId) || $lessonId === '' || ! PublishedLessonStorage::isValidId($lessonId)) {
            return ApiJson::error(
                ApiJson::MISSING_REQUIRED_FIELD,
                400,
                'Missing or invalid stage.id (must match your studio lesson)',
            );
        }

        $lesson = TutorLesson::query()->find($lessonId);
        if (! $lesson) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Lesson not found');
        }

        $this->authorize('update', $lesson);

        try {
            $result = $this->storage->persist($stage, $scenes, $this->publicBaseUrl($request));
        } catch (Throwable $e) {
            return ApiJson::error(
                ApiJson::INTERNAL_ERROR,
                500,
                'Failed to store published lesson',
                $e->getMessage(),
            );
        }

        return ApiJson::success([
            'id' => $result['id'],
            'url' => $result['url'],
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $id = $request->query('id');
        if (! is_string($id) || $id === '') {
            return ApiJson::error(
                ApiJson::MISSING_REQUIRED_FIELD,
                400,
                'Missing required parameter: id',
            );
        }

        if (! PublishedLessonStorage::isValidId($id)) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 400, 'Invalid lesson id');
        }

        $document = $this->storage->find($id);
        if ($document === null) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Lesson not found');
        }

        return ApiJson::success(['lesson' => $document]);
    }
}
