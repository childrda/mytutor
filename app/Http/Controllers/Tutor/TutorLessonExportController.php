<?php

namespace App\Http\Controllers\Tutor;

use App\Http\Controllers\Controller;
use App\Models\TutorLesson;
use App\Services\Export\LessonHtmlZipExportException;
use App\Services\Export\LessonHtmlZipExporter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TutorLessonExportController extends Controller
{
    public function htmlZip(TutorLesson $lesson, LessonHtmlZipExporter $exporter): BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $lesson);

        try {
            $out = $exporter->createZip($lesson);
        } catch (LessonHtmlZipExportException $e) {
            return response()->json([
                'success' => false,
                'errorCode' => $e->errorCode,
                'error' => $e->getMessage(),
            ], $e->httpStatus);
        }

        return response()->download($out['path'], $out['filename'], [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
