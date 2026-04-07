<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class TranscriptionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            return ApiJson::error(ApiJson::MISSING_REQUIRED_FIELD, 400, 'file is required');
        }

        $apiKey = (string) config('tutor.default_chat.api_key');
        if ($apiKey === '') {
            return ApiJson::error(ApiJson::MISSING_API_KEY, 401, 'ASR API key not configured');
        }

        $base = rtrim((string) config('tutor.default_chat.base_url'), '/');

        try {
            $res = Http::withToken($apiKey, 'Bearer')
                ->timeout(120)
                ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
                ->post($base.'/audio/transcriptions', [
                    'model' => 'whisper-1',
                ]);

            if (! $res->successful()) {
                return ApiJson::error(
                    ApiJson::TRANSCRIPTION_FAILED,
                    502,
                    'Transcription failed',
                    $res->body(),
                );
            }

            $text = (string) ($res->json('text') ?? '');

            return ApiJson::success(['text' => $text]);
        } catch (Throwable $e) {
            return ApiJson::error(
                ApiJson::TRANSCRIPTION_FAILED,
                502,
                'Transcription failed',
                $e->getMessage(),
            );
        }
    }
}
