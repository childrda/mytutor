<?php

namespace App\Services\Ai;

use App\Support\Chat\ChatSseProtocol;
use App\Support\Chat\TutorChatDirectorState;
use App\Support\TutorDefaultChatRuntime;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Streams OpenAI-compatible chat completions as SSE events compatible with
 * the stateless tutor chat protocol (agent_start, text_delta, agent_end, done, error).
 *
 * When multiple agent ids are provided, runs sequential completions: each agent
 * receives prior agents' outputs as assistant messages (multi-agent routing, Phase 3.2).
 *
 * When {@see config('tutor.active.llm')} selects an OpenAI-compatible registry entry, the request URL and auth headers
 * come from {@see LlmClient::openAiRegistryChatEndpointAndHeaders}; the JSON body is still built here (stream, messages, tools).
 *
 * When {@see ChatToolRegistry} defines tools, they are sent to the model; tool calls are
 * executed allowlist-only, emitted as {@see ChatSseProtocol::TYPE_ACTION}, then a follow-up
 * completion is streamed with the same tools plus tool_choice=none (Phase 3.3).
 */
class StatelessChatStreamer
{
    public function __construct(
        private readonly ?Client $httpClient = null,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $messages  OpenAI-format messages (from client UI model)
     * @param  list<string>  $agentIds
     * @param  Closure(string): void  $emit  Raw SSE line writer (without double newline)
     * @param  array<string, mixed>  $directorStateBaseline  Sanitized client directorState for merge into `done`
     */
    /**
     * @param  array{user_id?: int|null, source?: string}  $llmLogContext
     */
    public function stream(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        string $systemPreamble,
        array $agentIds,
        Closure $emit,
        array $directorStateBaseline = [],
        array $llmLogContext = [],
    ): void {
        $resolved = LlmClient::openAiRegistryChatEndpointAndHeaders($baseUrl, $apiKey, $model, true);
        if ($resolved !== null) {
            $url = $resolved['url'];
            $streamHeaders = $resolved['headers'];
            $effectiveApiKey = $apiKey;
        } else {
            $legacyBase = rtrim($baseUrl, '/');
            if ($legacyBase === '') {
                $legacyBase = rtrim((string) config('tutor.default_chat.base_url'), '/');
            }
            $url = $legacyBase !== '' ? $legacyBase.'/chat/completions' : '/chat/completions';
            $streamHeaders = null;
            $effectiveApiKey = trim($apiKey) !== '' ? $apiKey : TutorDefaultChatRuntime::apiKey();
        }
        $client = $this->client();
        $normalizedIds = TutorAgentRegistry::normalizeAgentIds($agentIds);
        $turnOutputs = [];
        $totalActionsAll = 0;

        foreach ($normalizedIds as $agentId) {
            $persona = TutorAgentRegistry::resolve($agentId);
            $messageId = (string) Str::ulid();

            $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_THINKING, [
                'stage' => 'director',
                'agentId' => $agentId,
            ]));

            $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_AGENT_START, [
                'messageId' => $messageId,
                'agentId' => $agentId,
                'agentName' => $persona['name'],
            ]));

            $systemContent = $systemPreamble."\n\n## Your role\n".$persona['persona'];
            $openAiMessages = array_merge(
                [['role' => 'system', 'content' => $systemContent]],
                $messages,
            );
            foreach ($turnOutputs as $prev) {
                $openAiMessages[] = [
                    'role' => 'assistant',
                    'content' => $prev['content'],
                ];
            }

            $turn = $this->runAgentOpenAiTurn($client, $url, $effectiveApiKey, $model, $openAiMessages, $messageId, $emit, $llmLogContext, $streamHeaders);
            if ($turn === false) {
                return;
            }

            $totalActionsAll += $turn['actions'];
            $turnOutputs[] = [
                'agentId' => $agentId,
                'name' => $persona['name'],
                'content' => $turn['content'],
            ];

            $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_AGENT_END, [
                'messageId' => $messageId,
                'agentId' => $agentId,
            ]));
        }

        $agentHadContent = $totalActionsAll > 0;
        foreach ($turnOutputs as $row) {
            if (trim($row['content']) !== '') {
                $agentHadContent = true;
                break;
            }
        }

        $thisTurnAgentResponses = array_map(static fn (array $row): array => [
            'agentId' => $row['agentId'],
            'agentName' => $row['name'],
            'content' => $row['content'],
        ], $turnOutputs);

        $mergedDirector = TutorChatDirectorState::mergeForDone(
            $directorStateBaseline,
            $thisTurnAgentResponses,
            [],
        );

        $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_DONE, [
            'totalActions' => $totalActionsAll,
            'totalAgents' => count($normalizedIds),
            'agentHadContent' => $agentHadContent,
            'directorState' => $mergedDirector,
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $openAiMessages
     * @param  array{user_id?: int|null, source?: string}  $llmLogContext
     * @return array{content: string, actions: int}|false
     */
    private function runAgentOpenAiTurn(
        Client $client,
        string $url,
        string $apiKey,
        string $model,
        array $openAiMessages,
        string $messageId,
        Closure $emit,
        array $llmLogContext = [],
        ?array $resolvedRequestHeaders = null,
    ): array|false {
        $logger = app(LlmExchangeLogger::class);
        $ctx = LlmExchangeLogger::mergeContext($llmLogContext);
        $toolsDef = ChatToolRegistry::openAiToolDefinitions();
        $usesTools = $toolsDef !== [];

        $payload = [
            'model' => $model,
            'stream' => true,
            'messages' => $openAiMessages,
        ];
        if ($usesTools) {
            $payload['tools'] = $toolsDef;
            $payload['tool_choice'] = 'auto';
        }

        $requestHeaders = $resolvedRequestHeaders ?? $this->requestHeaders($apiKey);

        $cidPrimary = (string) Str::ulid();
        if ($logger->enabled()) {
            $logger->record('sent', $cidPrimary, $ctx['user_id'], $ctx['source'], $payload, '/chat/completions', null, [
                'tutor_stream' => true,
                'messageId' => $messageId,
                'phase' => 'primary',
            ]);
        }

        try {
            $response = $client->post($url, [
                'json' => $payload,
                'stream' => true,
                'headers' => $requestHeaders,
            ]);
        } catch (GuzzleException $e) {
            if ($logger->enabled()) {
                $logger->record('received', $cidPrimary, $ctx['user_id'], $ctx['source'], [
                    'error' => self::formatUpstreamHttpError($e),
                ], '/chat/completions', null, [
                    'tutor_stream' => true,
                    'messageId' => $messageId,
                    'phase' => 'primary',
                    'request_failed' => true,
                ]);
            }
            $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_ERROR, [
                'message' => self::formatUpstreamHttpError($e),
            ]));

            return false;
        }

        $statusPrimary = $response->getStatusCode();
        $read = $this->readSseStream($response->getBody(), $messageId, $emit);
        if ($logger->enabled()) {
            $logger->record('received', $cidPrimary, $ctx['user_id'], $ctx['source'], [
                'content' => $read['content'],
                'toolCalls' => $read['toolCalls'],
                'finishReason' => $read['finishReason'],
                'aborted' => $read['aborted'],
                'upstreamError' => $read['upstreamError'] ?? false,
                'sse_transcript' => $read['sse_transcript'] ?? '',
            ], '/chat/completions', $statusPrimary, [
                'tutor_stream' => true,
                'messageId' => $messageId,
                'phase' => 'primary',
            ]);
        }
        if ($read['aborted'] || ($read['upstreamError'] ?? false)) {
            return false;
        }

        if (! $usesTools || $read['toolCalls'] === []) {
            return ['content' => $read['content'], 'actions' => 0];
        }

        foreach ($read['toolCalls'] as $tc) {
            if (! ChatToolRegistry::isRegistered($tc['name'])) {
                $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_ERROR, [
                    'message' => 'Unknown tool: '.$tc['name'],
                ]));

                return false;
            }
        }

        $actions = 0;
        $toolResultMessages = [];
        foreach ($read['toolCalls'] as $tc) {
            try {
                $args = ChatToolRegistry::parseAndValidateArguments($tc['name'], $tc['arguments']);
            } catch (InvalidToolArgumentsException $e) {
                $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_ERROR, [
                    'message' => $e->getMessage(),
                ]));

                return false;
            }

            $result = ChatToolRegistry::execute($tc['name'], $args);
            $actions++;

            $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_ACTION, [
                'messageId' => $messageId,
                'tool' => $tc['name'],
                'arguments' => $args,
                'result' => $result,
            ]));

            try {
                $toolResultMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tc['id'],
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ];
            } catch (\JsonException $e) {
                $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_ERROR, [
                    'message' => 'Failed to encode tool result: '.$e->getMessage(),
                ]));

                return false;
            }
        }

        $assistantToolCalls = [];
        foreach ($read['toolCalls'] as $tc) {
            $assistantToolCalls[] = [
                'id' => $tc['id'],
                'type' => 'function',
                'function' => [
                    'name' => $tc['name'],
                    'arguments' => $tc['arguments'] === '' ? '{}' : $tc['arguments'],
                ],
            ];
        }

        $assistantMessage = [
            'role' => 'assistant',
            'tool_calls' => $assistantToolCalls,
        ];
        if ($read['content'] !== '') {
            $assistantMessage['content'] = $read['content'];
        } else {
            $assistantMessage['content'] = null;
        }

        $followUpMessages = array_merge($openAiMessages, [$assistantMessage], $toolResultMessages);

        $followPayload = [
            'model' => $model,
            'stream' => true,
            'messages' => $followUpMessages,
        ];
        // OpenAI rejects tool_choice without a tools array; keep definitions and forbid another tool round.
        if ($usesTools) {
            $followPayload['tools'] = $toolsDef;
            $followPayload['tool_choice'] = 'none';
        }

        $cidFollow = (string) Str::ulid();
        if ($logger->enabled()) {
            $logger->record('sent', $cidFollow, $ctx['user_id'], $ctx['source'], $followPayload, '/chat/completions', null, [
                'tutor_stream' => true,
                'messageId' => $messageId,
                'phase' => 'tool_followup',
            ]);
        }

        try {
            $response2 = $client->post($url, [
                'json' => $followPayload,
                'stream' => true,
                'headers' => $requestHeaders,
            ]);
        } catch (GuzzleException $e) {
            if ($logger->enabled()) {
                $logger->record('received', $cidFollow, $ctx['user_id'], $ctx['source'], [
                    'error' => self::formatUpstreamHttpError($e),
                ], '/chat/completions', null, [
                    'tutor_stream' => true,
                    'messageId' => $messageId,
                    'phase' => 'tool_followup',
                    'request_failed' => true,
                ]);
            }
            $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_ERROR, [
                'message' => self::formatUpstreamHttpError($e),
            ]));

            return false;
        }

        $statusFollow = $response2->getStatusCode();
        $read2 = $this->readSseStream($response2->getBody(), $messageId, $emit);
        if ($logger->enabled()) {
            $logger->record('received', $cidFollow, $ctx['user_id'], $ctx['source'], [
                'content' => $read2['content'],
                'toolCalls' => $read2['toolCalls'],
                'finishReason' => $read2['finishReason'],
                'aborted' => $read2['aborted'],
                'upstreamError' => $read2['upstreamError'] ?? false,
                'sse_transcript' => $read2['sse_transcript'] ?? '',
            ], '/chat/completions', $statusFollow, [
                'tutor_stream' => true,
                'messageId' => $messageId,
                'phase' => 'tool_followup',
            ]);
        }
        if ($read2['aborted'] || ($read2['upstreamError'] ?? false)) {
            return false;
        }

        return [
            'content' => $read['content'].$read2['content'],
            'actions' => $actions,
        ];
    }

    /**
     * @return array{
     *     content: string,
     *     toolCalls: list<array{id: string, name: string, arguments: string}>,
     *     finishReason: ?string,
     *     aborted: bool,
     *     upstreamError?: bool,
     *     sse_transcript: string
     * }
     */
    private function readSseStream(
        StreamInterface $body,
        string $messageId,
        Closure $emit,
    ): array {
        $buffer = '';
        $accumulated = '';
        $toolCallAcc = [];
        $finishReason = null;
        $aborted = false;
        $sseTranscript = '';
        $sseCap = 500_000;

        try {
            while (! $body->eof()) {
                if (connection_aborted()) {
                    $aborted = true;
                    break;
                }

                try {
                    $chunk = $body->read(1024);
                } catch (Throwable $e) {
                    $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_ERROR, [
                        'message' => $e->getMessage(),
                    ]));

                    return [
                        'content' => $accumulated,
                        'toolCalls' => $this->finalizeToolCallsArray($toolCallAcc),
                        'finishReason' => $finishReason,
                        'aborted' => false,
                        'upstreamError' => true,
                        'sse_transcript' => $sseTranscript,
                    ];
                }

                if ($chunk === false || $chunk === '') {
                    continue;
                }

                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '' || str_starts_with($line, ':')) {
                        continue;
                    }
                    if (! str_starts_with($line, 'data:')) {
                        continue;
                    }
                    if (strlen($sseTranscript) < $sseCap) {
                        $add = $line."\n";
                        $room = $sseCap - strlen($sseTranscript);
                        if (strlen($add) > $room) {
                            $add = $room > 0 ? (substr($add, 0, $room)."\n…[capped]") : '';
                        }
                        $sseTranscript .= $add;
                    }
                    $json = trim(substr($line, 5));
                    if ($json === '[DONE]') {
                        break 2;
                    }
                    try {
                        $evt = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                    } catch (Throwable) {
                        continue;
                    }

                    $delta = data_get($evt, 'choices.0.delta.content');
                    if (is_string($delta) && $delta !== '') {
                        $accumulated .= $delta;
                        $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_TEXT_DELTA, [
                            'content' => $delta,
                            'messageId' => $messageId,
                        ]));
                        if (connection_aborted()) {
                            $aborted = true;
                            break 2;
                        }
                    }

                    $dtc = data_get($evt, 'choices.0.delta.tool_calls');
                    if (is_array($dtc)) {
                        $this->mergeToolCallDelta($toolCallAcc, $dtc);
                    }

                    $fr = data_get($evt, 'choices.0.finish_reason');
                    if (is_string($fr) && $fr !== '') {
                        $finishReason = $fr;
                    }
                }
            }
        } finally {
            if ($aborted) {
                $this->closeStreamIfPossible($body);
            }
        }

        return [
            'content' => $accumulated,
            'toolCalls' => $this->finalizeToolCallsArray($toolCallAcc),
            'finishReason' => $finishReason,
            'aborted' => $aborted,
            'sse_transcript' => $sseTranscript,
        ];
    }

    private function closeStreamIfPossible(StreamInterface $body): void
    {
        try {
            if (method_exists($body, 'close')) {
                $body->close();
            }
        } catch (Throwable) {
        }
    }

    /**
     * @param  array<int, array{id: string, name: string, arguments: string}>  $acc
     * @param  list<array<string, mixed>>  $deltaToolCalls
     */
    private function mergeToolCallDelta(array &$acc, array $deltaToolCalls): void
    {
        foreach ($deltaToolCalls as $d) {
            if (! is_array($d)) {
                continue;
            }
            $i = (int) ($d['index'] ?? 0);
            if (! isset($acc[$i])) {
                $acc[$i] = ['id' => '', 'name' => '', 'arguments' => ''];
            }
            if (isset($d['id']) && is_string($d['id']) && $d['id'] !== '') {
                $acc[$i]['id'] = $d['id'];
            }
            $fn = $d['function'] ?? null;
            if (is_array($fn)) {
                if (isset($fn['name']) && is_string($fn['name'])) {
                    $acc[$i]['name'] .= $fn['name'];
                }
                if (isset($fn['arguments']) && is_string($fn['arguments'])) {
                    $acc[$i]['arguments'] .= $fn['arguments'];
                }
            }
        }
    }

    /**
     * @param  array<int, array{id: string, name: string, arguments: string}>  $acc
     * @return list<array{id: string, name: string, arguments: string}>
     */
    private function finalizeToolCallsArray(array $acc): array
    {
        ksort($acc);
        $out = [];
        foreach ($acc as $row) {
            $name = trim($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $id = trim($row['id'] ?? '');
            if ($id === '') {
                $id = 'call_'.Str::lower(Str::ulid());
            }
            $out[] = [
                'id' => $id,
                'name' => $name,
                'arguments' => $row['arguments'] ?? '',
            ];
        }

        return $out;
    }

    private function client(): Client
    {
        return $this->httpClient ?? new Client([
            'timeout' => (float) config('tutor.chat_stream.guzzle_timeout', 115),
            'connect_timeout' => (float) config('tutor.chat_stream.guzzle_connect_timeout', 15),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function requestHeaders(string $apiKey): array
    {
        return [
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ];
    }

    private static function formatUpstreamHttpError(GuzzleException $e): string
    {
        $base = $e->getMessage();
        if (! $e instanceof RequestException) {
            return $base;
        }

        $response = $e->getResponse();
        if ($response === null) {
            return $base;
        }

        $raw = (string) $response->getBody();
        if ($raw === '') {
            return $base;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $base.' | '.(strlen($raw) > 400 ? substr($raw, 0, 400).'…' : $raw);
        }

        $apiMsg = is_array($decoded) ? data_get($decoded, 'error.message') : null;

        return is_string($apiMsg) && $apiMsg !== ''
            ? $base.' | '.$apiMsg
            : $base.' | '.(strlen($raw) > 400 ? substr($raw, 0, 400).'…' : $raw);
    }
}
