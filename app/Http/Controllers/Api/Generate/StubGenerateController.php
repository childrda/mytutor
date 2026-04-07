<?php

namespace App\Http\Controllers\Api\Generate;

use App\Http\Controllers\Controller;
use App\Services\MediaGeneration\GeneratedMediaStorage;
use App\Services\MediaGeneration\ImageGenerationException;
use App\Services\MediaGeneration\OpenAiImageGenerator;
use App\Services\MediaGeneration\OpenAiTtsGenerator;
use App\Services\MediaGeneration\TtsGenerationException;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Generation endpoints that depend on vendor-specific media APIs.
 */
class StubGenerateController extends Controller
{
    public function image(Request $request, OpenAiImageGenerator $generator, GeneratedMediaStorage $mediaStorage): JsonResponse
    {
        $body = $request->all();
        $prompt = isset($body['prompt']) && is_string($body['prompt']) ? $body['prompt'] : '';
        $size = isset($body['size']) && is_string($body['size']) ? trim($body['size']) : null;
        $model = isset($body['model']) && is_string($body['model']) ? trim($body['model']) : null;
        $clientKey = isset($body['apiKey']) && is_string($body['apiKey']) ? trim($body['apiKey']) : '';

        $requiresKey = ($body['requiresApiKey'] ?? true) !== false;
        $serverKey = (string) config('tutor.image_generation.api_key');
        if ($requiresKey && $clientKey === '' && $serverKey === '') {
            return ApiJson::error(ApiJson::MISSING_API_KEY, 401, 'API key is required');
        }

        $overrideKey = $clientKey !== '' ? $clientKey : null;

        try {
            $out = $generator->generate($prompt, $size, $model, $overrideKey);
        } catch (ImageGenerationException $e) {
            return ApiJson::error($e->errorCode, $e->httpStatus, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, 'Unexpected error during image generation');
        }

        try {
            $stored = $mediaStorage->storeBinary('image', 'png', $out['binary']);
        } catch (Throwable $e) {
            report($e);

            return ApiJson::error(ApiJson::GENERATION_FAILED, 500, 'Failed to store generated image');
        }

        return ApiJson::success([
            'provider' => 'openai-images',
            'url' => $stored['url'],
            'path' => $stored['relativePath'],
            'mime' => $out['mime'],
            'revisedPrompt' => $out['revisedPrompt'],
        ]);
    }

    public function tts(Request $request, OpenAiTtsGenerator $generator, GeneratedMediaStorage $mediaStorage): JsonResponse
    {
        $body = $request->all();
        $text = isset($body['text']) && is_string($body['text']) ? $body['text'] : '';
        $voice = isset($body['voice']) && is_string($body['voice']) ? trim($body['voice']) : null;
        $model = isset($body['model']) && is_string($body['model']) ? trim($body['model']) : null;
        $format = isset($body['format']) && is_string($body['format']) ? trim($body['format']) : null;
        $clientKey = isset($body['apiKey']) && is_string($body['apiKey']) ? trim($body['apiKey']) : '';

        $requiresKey = ($body['requiresApiKey'] ?? true) !== false;
        $serverKey = (string) config('tutor.tts_generation.api_key');
        if ($requiresKey && $clientKey === '' && $serverKey === '') {
            return ApiJson::error(ApiJson::MISSING_API_KEY, 401, 'API key is required');
        }

        $overrideKey = $clientKey !== '' ? $clientKey : null;

        try {
            $out = $generator->generate($text, $voice, $model, $format, $overrideKey);
        } catch (TtsGenerationException $e) {
            return ApiJson::error($e->errorCode, $e->httpStatus, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, 'Unexpected error during text-to-speech');
        }

        try {
            $stored = $mediaStorage->storeBinary('tts', $out['format'], $out['binary']);
        } catch (Throwable $e) {
            report($e);

            return ApiJson::error(ApiJson::GENERATION_FAILED, 500, 'Failed to store generated audio');
        }

        return ApiJson::success([
            'provider' => 'openai-tts',
            'url' => $stored['url'],
            'path' => $stored['relativePath'],
            'mime' => $out['mime'],
            'format' => $out['format'],
        ]);
    }
}
