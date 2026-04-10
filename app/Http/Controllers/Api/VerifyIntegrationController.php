<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\LlmClient;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationProbes;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class VerifyIntegrationController extends Controller
{
    public function model(Request $request): JsonResponse
    {
        $baseUrl = rtrim((string) $request->input('baseUrl', config('tutor.default_chat.base_url')), '/');
        $apiKey = (string) $request->input('apiKey', config('tutor.default_chat.api_key'));
        $model = (string) $request->input('model', config('tutor.default_chat.model'));

        if ($apiKey === '') {
            return ApiJson::error(ApiJson::MISSING_API_KEY, 401, 'apiKey is required');
        }

        try {
            $result = LlmClient::verifyChatCompletionsPing($baseUrl, $apiKey, $model, 30.0);
            if ($result['ok']) {
                return ApiJson::success(['ok' => true]);
            }

            $payload = ['ok' => false];
            if (isset($result['status'])) {
                $payload['status'] = $result['status'];
            }
            if (isset($result['body'])) {
                $payload['body'] = $result['body'];
            }
            if (isset($result['error'])) {
                $payload['error'] = $result['error'];
            }

            return ApiJson::success($payload);
        } catch (Throwable $e) {
            return ApiJson::success(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function image(Request $request): JsonResponse
    {
        $baseIn = $request->input('baseUrl');
        $baseUrl = (is_string($baseIn) && trim($baseIn) !== '')
            ? rtrim(trim($baseIn), '/')
            : rtrim((string) config('tutor.image_generation.base_url'), '/');

        $keyIn = $request->input('apiKey');
        $apiKey = (is_string($keyIn) && trim($keyIn) !== '')
            ? trim($keyIn)
            : (string) config('tutor.image_generation.api_key');

        if ($apiKey === '') {
            if (IntegrationCatalog::imageProviders() !== []) {
                return ApiJson::success([
                    'ok' => false,
                    'skipped' => true,
                    'message' => 'IMAGE_*_API_KEY values appear in /api/integrations, but this probe runs only when TUTOR_IMAGE_API_KEY, IMAGE_NANO_BANANA_API_KEY, TUTOR_DEFAULT_LLM_API_KEY, or OPENAI_API_KEY is set with an OpenAI-compatible base URL.',
                ]);
            }

            return ApiJson::success([
                'ok' => false,
                'message' => 'Set TUTOR_IMAGE_API_KEY and TUTOR_IMAGE_BASE_URL, or IMAGE_NANO_BANANA_API_KEY with TUTOR_IMAGE_BASE_URL / TUTOR_NANO_BANANA_IMAGE_BASE_URL (or default LLM keys), to probe server-side image credentials.',
            ]);
        }

        $timeout = min(30.0, max(10.0, (float) config('tutor.image_generation.timeout', 60)));
        $result = IntegrationProbes::openAiCompatibleAuth($baseUrl, $apiKey, $timeout);

        if ($result['ok']) {
            return ApiJson::success([
                'ok' => true,
                'probe' => $result['probe'],
                'message' => 'Upstream accepted credentials ('.$result['probe'].'). Does not invoke paid image generation.',
            ]);
        }

        return ApiJson::success([
            'ok' => false,
            'message' => 'Upstream rejected credentials or was unreachable.',
            'status' => $result['status'] ?? null,
            'error' => $result['error'] ?? null,
            'body' => $result['body'] ?? null,
        ]);
    }

    public function video(Request $request): JsonResponse
    {
        $baseIn = $request->input('baseUrl');
        $baseUrl = (is_string($baseIn) && trim($baseIn) !== '')
            ? rtrim(trim($baseIn), '/')
            : rtrim((string) config('tutor.video_generation.base_url'), '/');

        $keyIn = $request->input('apiKey');
        $apiKey = (is_string($keyIn) && trim($keyIn) !== '')
            ? trim($keyIn)
            : (string) config('tutor.video_generation.api_key');

        if ($apiKey === '') {
            if (IntegrationCatalog::videoProviders() !== []) {
                return ApiJson::success([
                    'ok' => false,
                    'skipped' => true,
                    'message' => 'VIDEO_*_API_KEY values appear in /api/integrations; server-side generation probes MiniMax only when TUTOR_VIDEO_API_KEY or VIDEO_MINIMAX_API_KEY is set.',
                ]);
            }

            return ApiJson::success([
                'ok' => false,
                'message' => 'Set TUTOR_VIDEO_API_KEY or VIDEO_MINIMAX_API_KEY to probe MiniMax video credentials.',
            ]);
        }

        $timeout = min(30.0, max(10.0, (float) config('tutor.video_generation.submit_timeout', 60)));
        $result = IntegrationProbes::minimaxVideoAuth($baseUrl, $apiKey, $timeout);

        if ($result['ok']) {
            return ApiJson::success([
                'ok' => true,
                'probe' => $result['probe'],
                'message' => 'MiniMax API key accepted ('.$result['probe'].').',
            ]);
        }

        return ApiJson::success([
            'ok' => false,
            'message' => 'MiniMax probe failed or key invalid.',
            'status' => $result['status'] ?? null,
            'baseRespCode' => $result['baseRespCode'] ?? null,
            'error' => $result['error'] ?? null,
        ]);
    }

    public function pdf(Request $request): JsonResponse
    {
        $configured = IntegrationCatalog::pdfProviders() !== [];

        return ApiJson::success([
            'ok' => $configured,
            'message' => 'PDF text extraction uses local parsing (smalot/pdfparser; tutor.pdf_parse). PDF_* env keys are catalog metadata; HTTP registry wiring for pdf is not used in the app yet.',
        ]);
    }
}
