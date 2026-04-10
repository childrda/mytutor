<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\ModelRegistry;
use App\Services\Ai\ModelRegistryException;
use App\Services\Ai\ModelRegistryHttpExecutor;
use App\Services\Ai\RegistryTemplateVarsResolver;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
        $base = rtrim((string) config('tutor.default_chat.base_url'), '/');

        $registry = app(ModelRegistry::class);
        if ($registry->hasActive('asr')) {
            $entry = $registry->activeEntry('asr');
            $resolved = RegistryTemplateVarsResolver::merge('asr', $entry, [
                'api_key' => $apiKey,
                'base_url' => $base,
            ]);
            $apiKey = (string) ($resolved['api_key'] ?? '');
            $base = rtrim((string) ($resolved['base_url'] ?? ''), '/');
        }

        if ($apiKey === '') {
            return ApiJson::error(ApiJson::MISSING_API_KEY, 401, 'ASR API key not configured');
        }

        if ($registry->hasActive('asr')) {
            return $this->transcribeViaModelRegistry($request, $file, $apiKey, $base);
        }

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

    private function transcribeViaModelRegistry(Request $request, UploadedFile $file, string $apiKey, string $base): JsonResponse
    {
        $registry = app(ModelRegistry::class);
        $entry = $registry->activeEntry('asr');
        if (! isset($entry['request_format']) || ! is_array($entry['request_format'])) {
            $key = $registry->activeKey('asr') ?? '';

            return ApiJson::error(
                ApiJson::INVALID_REQUEST,
                400,
                'Active ASR registry provider "'.$key.'" has no request_format (stub). '
                .'Set TUTOR_ACTIVE_ASR, save an executable provider in Settings (when env is unset), or clear the active key for legacy transcription.',
            );
        }

        $realPath = $file->getRealPath();
        $filePayload = ['filename' => $file->getClientOriginalName()];
        if (is_string($realPath) && $realPath !== '' && is_readable($realPath)) {
            $filePayload['path'] = $realPath;
        } else {
            try {
                $filePayload['contents'] = $file->getContent();
            } catch (Throwable) {
                return ApiJson::error(ApiJson::INVALID_REQUEST, 400, 'Uploaded file is not readable');
            }
        }

        $vars = [
            'api_key' => $apiKey,
            'base_url' => $base,
            'audio_file' => $filePayload,
            'timeout' => 120.0,
        ];

        $model = $request->input('model');
        if (is_string($model) && trim($model) !== '') {
            $vars['model'] = trim($model);
        }

        $language = $request->input('language');
        if (is_string($language) && trim($language) !== '') {
            $vars['language'] = trim($language);
        }

        try {
            $result = app(ModelRegistryHttpExecutor::class)->execute($entry, $vars);
        } catch (ModelRegistryException $e) {
            return self::jsonFromModelRegistryException($e);
        }

        $text = '';
        if (is_string($result->extracted)) {
            $text = $result->extracted;
        } elseif (is_array($result->json)) {
            $text = (string) ($result->json['text'] ?? '');
        }

        return ApiJson::success(['text' => $text]);
    }

    private static function jsonFromModelRegistryException(ModelRegistryException $e): JsonResponse
    {
        $msg = $e->getMessage();
        $lower = strtolower($msg);
        $httpStatus = self::parseRegistryHttpFailureStatus($e);

        if (str_contains($lower, 'missing template variable')
            || str_contains($lower, 'invalid model registry provider entry')) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 400, $msg);
        }

        if ($httpStatus === 401) {
            return ApiJson::error(ApiJson::MISSING_API_KEY, 401, 'Invalid or missing API key', $msg);
        }

        return ApiJson::error(ApiJson::TRANSCRIPTION_FAILED, 502, 'Transcription failed', $msg);
    }

    private static function parseRegistryHttpFailureStatus(ModelRegistryException $e): ?int
    {
        if (preg_match('/Model registry HTTP request failed \\((\\d+)\\):/', $e->getMessage(), $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
