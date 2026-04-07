<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\TutorLessonResource;
use App\Http\Resources\TutorSceneResource;
use App\Models\TutorLesson;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ClassroomLessonController extends Controller
{
    public function show(Request $request, TutorLesson $lesson): Response
    {
        if ($request->user()->cannot('view', $lesson)) {
            throw new NotFoundHttpException;
        }

        $lesson->load(['scenes' => fn ($q) => $q->orderBy('scene_order')]);
        $head = clone $lesson;
        $head->unsetRelation('scenes');

        return Inertia::render('Lessons/ClassroomShow', [
            'stage' => (new TutorLessonResource($head))->resolve(),
            'scenes' => TutorSceneResource::collection($lesson->scenes)->resolve(),
        ]);
    }
}
