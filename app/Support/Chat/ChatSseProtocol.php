<?php

namespace App\Support\Chat;

/**
 * Canonical SSE framing for POST /api/chat (Phase 3.1).
 *
 * Every frame is one SSE "data:" line whose JSON decodes to:
 *   { "type": string, "data": object }
 *
 * Bump {@see ChatSseProtocol::VERSION} and X-Chat-Protocol-Version when breaking clients.
 *
 * Event types (v1):
 * - thinking      — data: stage?, agentId?
 * - agent_start   — data: messageId, agentId, agentName
 * - text_delta    — data: messageId, content
 * - action        — data: messageId?, tool, arguments?, result? (tool execution / board ops)
 * - agent_end     — data: messageId, agentId
 * - done          — data: totalActions, totalAgents, agentHadContent, directorState?, …
 * - error         — data: message
 */
final class ChatSseProtocol
{
    public const int VERSION = 1;

    public const string TYPE_THINKING = 'thinking';

    public const string TYPE_AGENT_START = 'agent_start';

    public const string TYPE_TEXT_DELTA = 'text_delta';

    public const string TYPE_ACTION = 'action';

    public const string TYPE_AGENT_END = 'agent_end';

    public const string TYPE_DONE = 'done';

    public const string TYPE_ERROR = 'error';

    /**
     * @param  array<string, mixed>  $data
     */
    public static function frame(string $type, array $data): string
    {
        $payload = [
            'type' => $type,
            'data' => $data,
        ];

        return 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
    }
}
