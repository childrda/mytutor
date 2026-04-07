<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PublishedLesson;
use App\Services\PublishedLesson\PublishedLessonStorage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicLessonController extends Controller
{
    public function show(string $id): Response
    {
        if (! PublishedLessonStorage::isValidId($id)) {
            throw new NotFoundHttpException;
        }

        $row = PublishedLesson::query()->find($id);
        if (! $row) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('Lesson/Public', [
            'lesson' => $row->document,
        ]);
    }
}
