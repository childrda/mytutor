<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Documents\PdfTextExtractionException;
use App\Services\Documents\PdfTextExtractor;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ParsePdfController extends Controller
{
    public function __invoke(Request $request, PdfTextExtractor $extractor): JsonResponse
    {
        if (! $request->hasFile('file')) {
            return ApiJson::error(ApiJson::MISSING_REQUIRED_FIELD, 400, 'file is required');
        }

        $file = $request->file('file');
        if (! $file->isValid()) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 400, 'Invalid upload');
        }

        $mime = strtolower((string) $file->getMimeType());
        $clientMime = strtolower((string) $file->getClientMimeType());
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $allowed = ['application/pdf', 'application/x-pdf'];
        $looksPdf = in_array($mime, $allowed, true)
            || in_array($clientMime, $allowed, true)
            || $ext === 'pdf';
        if (! $looksPdf) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, 'Only PDF files are accepted');
        }

        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, 'Could not read uploaded file');
        }

        $fileSize = (int) $file->getSize();
        if ($fileSize <= 0) {
            $fileSize = (int) (@filesize($path) ?: 0);
        }

        try {
            $out = $extractor->extractFromPath($path, $fileSize);
        } catch (PdfTextExtractionException $e) {
            return ApiJson::error($e->errorCode, $e->httpStatus, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, 'Unexpected error during PDF parsing');
        }

        return ApiJson::success([
            'text' => $out['text'],
            'meta' => [
                'pages' => $out['pages'],
                'chars' => mb_strlen($out['text']),
                'truncated' => $out['truncated'],
                'fileSizeBytes' => $out['fileSizeBytes'],
            ],
        ]);
    }
}
