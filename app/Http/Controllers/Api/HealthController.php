<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integrations\IntegrationCatalog;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $web = IntegrationCatalog::webSearchProviders();
        $videoConfigured = count(IntegrationCatalog::videoProviders()) > 0
            || (string) config('tutor.video_generation.api_key') !== '';

        return ApiJson::success([
            'status' => 'ok',
            'version' => config('tutor.app_version'),
            'capabilities' => [
                'webSearch' => count($web) > 0,
                'imageGeneration' => count(IntegrationCatalog::imageProviders()) > 0,
                'videoGeneration' => $videoConfigured,
                'tts' => count(IntegrationCatalog::ttsProviders()) > 0,
            ],
        ]);
    }
}
