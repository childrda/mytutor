<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StudioGeneration\StudioSceneGenerationService;
use App\Support\ApiJson;
use App\Support\Generate\StudioGenerationSseProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class StudioSceneGenerationController extends Controller
{
    public function __construct(
        private readonly StudioSceneGenerationService $studio,
    ) {}

    public function sceneOutlinesStream(Request $request): JsonResponse|StreamedResponse
    {
        $creds = $this->resolveLlmCredentials($request);
        if ($creds instanceof JsonResponse) {
            return $creds;
        }

        $body = $request->all();
        $maxExec = (int) config('tutor.chat_stream.max_execution_seconds', 120);

        return response()->stream(function () use ($body, $creds, $maxExec): void {
            @set_time_limit($maxExec);
            $emit = static function (string $frame): void {
                if (connection_aborted()) {
                    return;
                }
                echo $frame;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $this->studio->streamSceneOutlines(
                $emit,
                $creds['baseUrl'],
                $creds['apiKey'],
                $creds['model'],
                is_array($body) ? $body : [],
            );
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'X-Studio-Generation-Sse-Version' => (string) StudioGenerationSseProtocol::VERSION,
        ]);
    }

    public function sceneActions(Request $request): JsonResponse
    {
        $creds = $this->resolveLlmCredentials($request);
        if ($creds instanceof JsonResponse) {
            return $creds;
        }

        try {
            $out = $this->studio->generateSceneActions(
                $creds['baseUrl'],
                $creds['apiKey'],
                $creds['model'],
                $request->all(),
            );
        } catch (\InvalidArgumentException $e) {
            return ApiJson::error(ApiJson::MISSING_REQUIRED_FIELD, 400, $e->getMessage());
        } catch (JsonException) {
            return ApiJson::error(ApiJson::PARSE_FAILED, 422, 'Model returned invalid JSON');
        } catch (RuntimeException $e) {
            return ApiJson::error(ApiJson::GENERATION_FAILED, 422, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return ApiJson::error(ApiJson::UPSTREAM_ERROR, 502, $e->getMessage());
        }

        return ApiJson::success($out);
    }

    public function sceneContent(Request $request): JsonResponse
    {
        $creds = $this->resolveLlmCredentials($request);
        if ($creds instanceof JsonResponse) {
            return $creds;
        }

        try {
            $out = $this->studio->generateSceneContent(
                $creds['baseUrl'],
                $creds['apiKey'],
                $creds['model'],
                $request->all(),
            );
        } catch (\InvalidArgumentException $e) {
            return ApiJson::error(ApiJson::MISSING_REQUIRED_FIELD, 400, $e->getMessage());
        } catch (JsonException) {
            return ApiJson::error(ApiJson::PARSE_FAILED, 422, 'Model returned invalid JSON');
        } catch (RuntimeException $e) {
            return ApiJson::error(ApiJson::GENERATION_FAILED, 422, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return ApiJson::error(ApiJson::UPSTREAM_ERROR, 502, $e->getMessage());
        }

        return ApiJson::success($out);
    }

    public function agentProfiles(Request $request): JsonResponse
    {
        $creds = $this->resolveLlmCredentials($request);
        if ($creds instanceof JsonResponse) {
            return $creds;
        }

        try {
            $out = $this->studio->generateAgentProfiles(
                $creds['baseUrl'],
                $creds['apiKey'],
                $creds['model'],
                $request->all(),
            );
        } catch (\InvalidArgumentException $e) {
            return ApiJson::error(ApiJson::MISSING_REQUIRED_FIELD, 400, $e->getMessage());
        } catch (JsonException) {
            return ApiJson::error(ApiJson::PARSE_FAILED, 422, 'Model returned invalid JSON');
        } catch (Throwable $e) {
            report($e);

            return ApiJson::error(ApiJson::UPSTREAM_ERROR, 502, $e->getMessage());
        }

        return ApiJson::success($out);
    }

    /**
     * @return JsonResponse|array{baseUrl: string, model: string, apiKey: string}
     */
    private function resolveLlmCredentials(Request $request): JsonResponse|array
    {
        $body = $request->all();
        $clientKey = isset($body['apiKey']) && is_string($body['apiKey']) ? trim($body['apiKey']) : '';
        $baseUrl = isset($body['baseUrl']) && is_string($body['baseUrl']) && $body['baseUrl'] !== ''
            ? rtrim($body['baseUrl'], '/')
            : rtrim((string) config('tutor.default_chat.base_url'), '/');
        $model = isset($body['model']) && is_string($body['model']) && $body['model'] !== ''
            ? trim($body['model'])
            : (string) config('tutor.default_chat.model');
        $apiKey = $clientKey !== '' ? $clientKey : (string) config('tutor.default_chat.api_key');

        $requiresKey = ($body['requiresApiKey'] ?? true) !== false;
        if ($requiresKey && $apiKey === '') {
            return ApiJson::error(ApiJson::MISSING_API_KEY, 401, 'API key is required');
        }

        return [
            'baseUrl' => $baseUrl,
            'model' => $model,
            'apiKey' => $apiKey,
        ];
    }
}
