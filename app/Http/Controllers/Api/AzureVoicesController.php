<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class AzureVoicesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $region = (string) $request->query('region', env('TTS_AZURE_REGION', ''));
        $key = (string) env('TTS_AZURE_API_KEY', '');
        if ($key === '' || $region === '') {
            return ApiJson::success(['voices' => []]);
        }

        $url = sprintf('https://%s.tts.speech.microsoft.com/cognitiveservices/voices/list', $region);

        try {
            $res = Http::withHeaders(['Ocp-Apim-Subscription-Key' => $key])
                ->timeout(30)
                ->get($url);

            if (! $res->successful()) {
                return ApiJson::success(['voices' => [], 'error' => $res->body()]);
            }

            return ApiJson::success(['voices' => $res->json() ?? []]);
        } catch (Throwable $e) {
            return ApiJson::success(['voices' => [], 'error' => $e->getMessage()]);
        }
    }
}
