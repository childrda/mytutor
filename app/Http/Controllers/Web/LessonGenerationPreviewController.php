<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\LessonGenerationJob;
use App\Support\LessonGeneration\LessonGenerationPhases;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LessonGenerationPreviewController extends Controller
{
    public function __invoke(Request $request, string $job): Response
    {
        $record = LessonGenerationJob::query()->find($job);
        if (! $record) {
            throw new NotFoundHttpException;
        }

        $this->authorize('view', $record);

        return Inertia::render('GenerationPreview', [
            'jobId' => $record->id,
            'pollIntervalMs' => 3000,
            'pipelineSteps' => LessonGenerationPhases::pipelineSteps(),
        ]);
    }
}
