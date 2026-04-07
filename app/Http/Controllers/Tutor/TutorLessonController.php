<?php

namespace App\Http\Controllers\Tutor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tutor\StoreTutorLessonRequest;
use App\Http\Requests\Tutor\UpdateTutorLessonRequest;
use App\Http\Resources\TutorLessonResource;
use App\Http\Resources\TutorSceneResource;
use App\Models\TutorLesson;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TutorLessonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $lessons = TutorLesson::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get();

        return ApiJson::success([
            'lessons' => TutorLessonResource::collection($lessons),
        ]);
    }

    public function store(StoreTutorLessonRequest $request): JsonResponse
    {
        $data = $request->validated();
        $lesson = TutorLesson::query()->create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'language' => $data['language'] ?? null,
            'style' => $data['style'] ?? null,
            'current_scene_id' => $data['currentSceneId'] ?? null,
            'agent_ids' => $data['agentIds'] ?? null,
            'meta' => $data['meta'] ?? null,
        ]);

        return ApiJson::success([
            'lesson' => new TutorLessonResource($lesson),
        ], 201);
    }

    public function show(TutorLesson $lesson): JsonResponse
    {
        $this->authorize('view', $lesson);

        $lesson->load(['scenes' => fn ($q) => $q->orderBy('scene_order')]);
        $scenes = $lesson->scenes;
        $head = clone $lesson;
        $head->unsetRelation('scenes');

        return ApiJson::success([
            'stage' => (new TutorLessonResource($head))->resolve(),
            'lesson' => (new TutorLessonResource($head))->resolve(),
            'scenes' => TutorSceneResource::collection($scenes)->resolve(),
        ]);
    }

    public function update(UpdateTutorLessonRequest $request, TutorLesson $lesson): JsonResponse
    {
        $data = $request->validated();
        $map = [
            'name' => 'name',
            'description' => 'description',
            'language' => 'language',
            'style' => 'style',
            'currentSceneId' => 'current_scene_id',
            'agentIds' => 'agent_ids',
            'meta' => 'meta',
        ];
        $attrs = [];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $data)) {
                $attrs[$snake] = $data[$camel];
            }
        }
        if ($attrs !== []) {
            $lesson->update($attrs);
        }

        return ApiJson::success([
            'lesson' => new TutorLessonResource($lesson->fresh()),
        ]);
    }

    public function destroy(TutorLesson $lesson): JsonResponse
    {
        $this->authorize('delete', $lesson);

        $lesson->delete();

        return ApiJson::success(['deleted' => true]);
    }
}
