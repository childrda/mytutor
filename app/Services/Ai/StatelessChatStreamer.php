<?php

namespace App\Services\Ai;

use App\Support\Chat\ChatSseProtocol;
use App\Support\Chat\TutorChatDirectorState;
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
 * When {@see ChatToolRegistry} defines tools, they are sent to the model; tool calls are
 * executed allowlist-only, emitted as {@see ChatSseProtocol::TYPE_ACTION}, then a follow-up
 * completion is streamed with tool_choice=none (Phase 3.3).
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
    public function stream(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        string $systemPreamble,
        array $agentIds,
        Closure $emit,
        array $directorStateBaseline = [],
    ): void {
        $url = rtrim($baseUrl, '/').'/chat/completions';
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

            $turn = $this->runAgentOpenAiTurn($client, $url, $apiKey, $model, $openAiMessages, $messageId, $emit);
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
    ): array|false {
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

        try {
            $response = $client->post($url, [
                'json' => $payload,
                'stream' => true,
                'headers' => $this->requestHeaders($apiKey),
            ]);
        } catch (GuzzleException $e) {
            $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_ERROR, [
                'message' => self::formatUpstreamHttpError($e),
            ]));

            return false;
        }

        $read = $this->readSseStream($response->getBody(), $messageId, $emit);
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
            'tool_choice' => 'none',
        ];

        try {
            $response2 = $client->post($url, [
                'json' => $followPayload,
                'stream' => true,
                'headers' => $this->requestHeaders($apiKey),
            ]);
        } catch (GuzzleException $e) {
            $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_ERROR, [
                'message' => self::formatUpstreamHttpError($e),
            ]));

            return false;
        }

        $read2 = $this->readSseStream($response2->getBody(), $messageId, $emit);
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
     *     upstreamError?: bool
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
