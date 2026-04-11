<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\StatelessChatStreamer;
use App\Support\Chat\ChatSseProtocol;
use App\Support\Chat\TutorChatDirectorState;
use App\Support\Chat\TutorChatPromptBuilder;
use App\Support\Chat\TutorChatRequestContext;
use App\Support\Chat\TutorChatRequestLimits;
use App\Support\TutorDefaultChatRuntime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __invoke(Request $request, StatelessChatStreamer $streamer): JsonResponse|StreamedResponse
    {
        $body = $request->json()->all();

        if (! isset($body['messages']) || ! is_array($body['messages'])) {
            return response()->json([
                'success' => false,
                'errorCode' => 'MISSING_REQUIRED_FIELD',
                'error' => 'Missing required field: messages',
            ], 400);
        }

        if (! isset($body['storeState']) || ! is_array($body['storeState'])) {
            return response()->json([
                'success' => false,
                'errorCode' => 'MISSING_REQUIRED_FIELD',
                'error' => 'Missing required field: storeState',
            ], 400);
        }

        $config = $body['config'] ?? [];
        $agentIds = is_array($config) && isset($config['agentIds']) && is_array($config['agentIds'])
            ? $config['agentIds']
            : [];
        if ($agentIds === []) {
            return response()->json([
                'success' => false,
                'errorCode' => 'MISSING_REQUIRED_FIELD',
                'error' => 'Missing required field: config.agentIds',
            ], 400);
        }

        $rawLimit = TutorChatRequestLimits::validateRawMessageCount($body['messages']);
        if (! $rawLimit['ok']) {
            return response()->json([
                'success' => false,
                'errorCode' => $rawLimit['errorCode'],
                'error' => $rawLimit['error'],
            ], 400);
        }

        $clientKey = is_string($body['apiKey'] ?? null) ? $body['apiKey'] : '';
        $baseUrl = is_string($body['baseUrl'] ?? null) && $body['baseUrl'] !== ''
            ? $body['baseUrl']
            : (string) config('tutor.default_chat.base_url');
        $model = is_string($body['model'] ?? null) && $body['model'] !== ''
            ? $body['model']
            : (string) config('tutor.default_chat.model');
        $apiKey = $clientKey !== '' ? $clientKey : TutorDefaultChatRuntime::apiKey();

        $requiresKey = ($body['requiresApiKey'] ?? true) !== false;
        if ($requiresKey && $apiKey === '') {
            return response()->json([
                'success' => false,
                'errorCode' => 'MISSING_API_KEY',
                'error' => 'API Key is required',
            ], 401);
        }

        $ctx = TutorChatRequestContext::fromRequestBody($body);
        $systemPreamble = TutorChatPromptBuilder::build($ctx);
        $directorBaseline = TutorChatDirectorState::sanitizeIncoming($body['directorState'] ?? null);

        $openAiMessages = self::mapUiMessagesToChat($body['messages']);
        $mappedLimit = TutorChatRequestLimits::validateMappedMessages($openAiMessages);
        if (! $mappedLimit['ok']) {
            return response()->json([
                'success' => false,
                'errorCode' => $mappedLimit['errorCode'],
                'error' => $mappedLimit['error'],
            ], 400);
        }

        $maxExec = (int) config('tutor.chat_stream.max_execution_seconds', 120);

        return response()->stream(function () use ($streamer, $baseUrl, $apiKey, $model, $openAiMessages, $systemPreamble, $agentIds, $directorBaseline, $maxExec): void {
            @set_time_limit($maxExec);
            $streamer->stream(
                $baseUrl,
                $apiKey,
                $model,
                $openAiMessages,
                $systemPreamble,
                $agentIds,
                function (string $chunk): void {
                    if (connection_aborted()) {
                        return;
                    }
                    echo $chunk;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                },
                $directorBaseline,
                [
                    'user_id' => auth()->id(),
                    'source' => 'tutor_chat_stream',
                ],
            );
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'X-Chat-Protocol-Version' => (string) ChatSseProtocol::VERSION,
        ]);
    }

    /**
     * @param  list<mixed>  $uiMessages
     * @return list<array{role: string, content: string}>
     */
    private static function mapUiMessagesToChat(array $uiMessages): array
    {
        $out = [];
        foreach ($uiMessages as $m) {
            if (! is_array($m)) {
                continue;
            }
            $role = $m['role'] ?? 'user';
            $role = is_string($role) ? $role : 'user';
            if (! in_array($role, ['user', 'assistant', 'system'], true)) {
                $role = 'user';
            }
            $content = self::extractTextContent($m);
            if ($content === '') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $m
     */
    private static function extractTextContent(array $m): string
    {
        if (isset($m['content']) && is_string($m['content'])) {
            return $m['content'];
        }
        if (isset($m['parts']) && is_array($m['parts'])) {
            $text = '';
            foreach ($m['parts'] as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text' && isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }

            return $text;
        }

        return '';
    }
}
