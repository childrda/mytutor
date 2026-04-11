<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\LlmClient;
use App\Services\Ai\ModelRegistry;
use App\Support\ApiJson;
use App\Support\TutorDefaultChatRuntime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Project-style tutoring thread (mention routing can be layered on the client).
 */
class ProjectTutorChatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $messages = $request->input('messages');
        if (! is_array($messages) || $messages === []) {
            return ApiJson::error(ApiJson::MISSING_REQUIRED_FIELD, 400, 'messages are required');
        }

        $clientKey = trim((string) $request->input('apiKey', ''));
        $bodyBase = $request->input('baseUrl');
        $bodyModel = $request->input('model');
        $bodyBaseStr = is_string($bodyBase) && trim($bodyBase) !== '' ? trim($bodyBase) : null;
        $bodyModelStr = is_string($bodyModel) && trim($bodyModel) !== '' ? trim($bodyModel) : null;
        $baseUrl = TutorDefaultChatRuntime::resolvedWireBaseUrl($bodyBaseStr);
        $model = TutorDefaultChatRuntime::resolvedWireModel($bodyModelStr);
        $apiKey = TutorDefaultChatRuntime::resolvedWireApiKey($clientKey);

        if (trim($apiKey) === '' && ! app(ModelRegistry::class)->hasActive('llm')) {
            return ApiJson::error(ApiJson::MISSING_API_KEY, 401, 'API key is required');
        }

        $mapped = [];
        foreach ($messages as $m) {
            if (! is_array($m)) {
                continue;
            }
            $role = in_array($m['role'] ?? '', ['user', 'assistant', 'system'], true)
                ? $m['role']
                : 'user';
            $content = is_string($m['content'] ?? null) ? $m['content'] : json_encode($m['content'] ?? '');
            $mapped[] = ['role' => $role, 'content' => $content];
        }

        try {
            $reply = LlmClient::chat($baseUrl, $apiKey, $model, $mapped, 0.5, 2048, [
                'user_id' => $request->user()?->getKey(),
                'source' => 'project_tutor_chat',
            ]);

            return ApiJson::success(['message' => ['role' => 'assistant', 'content' => $reply]]);
        } catch (Throwable $e) {
            return ApiJson::error(
                ApiJson::UPSTREAM_ERROR,
                502,
                'Chat failed',
                $e->getMessage(),
            );
        }
    }
}
