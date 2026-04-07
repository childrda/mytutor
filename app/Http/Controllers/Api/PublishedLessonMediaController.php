<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PublishedLesson\PublishedLessonStorage;
use App\Support\ApiJson;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublishedLessonMediaController extends Controller
{
    public function __construct(
        private readonly PublishedLessonStorage $storage,
    ) {}

    public function show(Request $request, string $lessonId, string $path): StreamedResponse|Response
    {
        if (! PublishedLessonStorage::isValidId($lessonId)) {
            return response()->json([
                'success' => false,
                'errorCode' => ApiJson::INVALID_REQUEST,
                'error' => 'Invalid lesson id',
            ], 400);
        }

        $relative = str_replace('..', '', $path);
        $relative = ltrim($relative, '/');
        $full = $this->storage->mediaPath($lessonId, $relative);

        $disk = $this->storage->mediaDisk();
        if (! $disk->exists($full)) {
            return response()->json([
                'success' => false,
                'errorCode' => ApiJson::INVALID_REQUEST,
                'error' => 'File not found',
            ], 404);
        }

        $stream = $disk->readStream($full);
        if (! is_resource($stream)) {
            return response()->json([
                'success' => false,
                'errorCode' => ApiJson::INTERNAL_ERROR,
                'error' => 'Unable to read file',
            ], 500);
        }

        $mime = $disk->mimeType($full) ?: 'application/octet-stream';

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
