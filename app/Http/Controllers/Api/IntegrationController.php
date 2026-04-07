<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integrations\IntegrationCatalog;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Throwable;

class IntegrationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            return ApiJson::success([
                'providers' => IntegrationCatalog::llmProviders(),
                'tts' => IntegrationCatalog::ttsProviders(),
                'asr' => IntegrationCatalog::asrProviders(),
                'pdf' => IntegrationCatalog::pdfProviders(),
                'image' => IntegrationCatalog::imageProviders(),
                'video' => IntegrationCatalog::videoProviders(),
                'webSearch' => IntegrationCatalog::webSearchProviders(),
            ]);
        } catch (Throwable $e) {
            return ApiJson::error(
                ApiJson::INTERNAL_ERROR,
                500,
                $e->getMessage(),
            );
        }
    }
}
