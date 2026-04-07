<?php

namespace App\Http\Controllers\Tutor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tutor\ReorderTutorScenesRequest;
use App\Http\Requests\Tutor\StoreTutorSceneRequest;
use App\Http\Requests\Tutor\UpdateTutorSceneRequest;
use App\Http\Resources\TutorSceneResource;
use App\Models\TutorLesson;
use App\Models\TutorScene;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TutorSceneController extends Controller
{
    public function store(StoreTutorSceneRequest $request, TutorLesson $lesson): JsonResponse
    {
        $data = $request->validated();
        $maxOrder = (int) $lesson->scenes()->max('scene_order');
        $order = array_key_exists('order', $data) ? (int) $data['order'] : $maxOrder + 1;

        $scene = $lesson->scenes()->create([
            'type' => $data['type'],
            'title' => $data['title'],
            'scene_order' => $order,
            'content' => $data['content'],
            'actions' => $data['actions'] ?? null,
            'whiteboard' => $data['whiteboards'] ?? null,
            'multi_agent' => $data['multiAgent'] ?? null,
        ]);

        return ApiJson::success([
            'scene' => new TutorSceneResource($scene),
        ], 201);
    }

    public function update(UpdateTutorSceneRequest $request, TutorLesson $lesson, TutorScene $scene): JsonResponse
    {
        if ($scene->tutor_lesson_id !== $lesson->id) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Scene not found for this lesson');
        }

        $data = $request->validated();
        $attrs = [];
        if (array_key_exists('type', $data)) {
            $attrs['type'] = $data['type'];
        }
        if (array_key_exists('title', $data)) {
            $attrs['title'] = $data['title'];
        }
        if (array_key_exists('order', $data)) {
            $attrs['scene_order'] = $data['order'];
        }
        if (array_key_exists('content', $data)) {
            $attrs['content'] = $data['content'];
        }
        if (array_key_exists('actions', $data)) {
            $attrs['actions'] = $data['actions'];
        }
        if (array_key_exists('whiteboards', $data)) {
            $attrs['whiteboard'] = $data['whiteboards'];
        }
        if (array_key_exists('multiAgent', $data)) {
            $attrs['multi_agent'] = $data['multiAgent'];
        }

        if ($attrs !== []) {
            $scene->update($attrs);
        }

        return ApiJson::success([
            'scene' => new TutorSceneResource($scene->fresh()),
        ]);
    }

    public function destroy(TutorLesson $lesson, TutorScene $scene): JsonResponse
    {
        if ($scene->tutor_lesson_id !== $lesson->id) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Scene not found for this lesson');
        }

        $this->authorize('delete', $scene);

        $scene->delete();

        return ApiJson::success(['deleted' => true]);
    }

    public function reorder(ReorderTutorScenesRequest $request, TutorLesson $lesson): JsonResponse
    {
        $ids = $request->validated('sceneIds');
        $owned = $lesson->scenes()->pluck('id')->all();

        if ($owned === []) {
            return ApiJson::success(['scenes' => []]);
        }

        $ownedSet = array_flip($owned);

        foreach ($ids as $id) {
            if (! isset($ownedSet[$id])) {
                return ApiJson::error(ApiJson::INVALID_REQUEST, 422, 'Unknown scene id in list', $id);
            }
        }

        if (count(array_unique($ids)) !== count($ids)) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, 'Duplicate scene ids');
        }

        if (count($ids) !== count($owned)) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, 'sceneIds must include every scene for this lesson');
        }

        DB::transaction(function () use ($ids, $lesson): void {
            foreach ($ids as $index => $id) {
                TutorScene::query()->where('id', $id)->where('tutor_lesson_id', $lesson->id)->update([
                    'scene_order' => $index,
                ]);
            }
        });

        $lesson->load(['scenes' => fn ($q) => $q->orderBy('scene_order')]);

        return ApiJson::success([
            'scenes' => TutorSceneResource::collection($lesson->scenes)->resolve(),
        ]);
    }
}
