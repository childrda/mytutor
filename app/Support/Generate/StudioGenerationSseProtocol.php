<?php

namespace App\Support\Generate;

/**
 * SSE framing for POST /api/generate/scene-outlines-stream (Phase 4.5).
 *
 * Each event is one line: {@code data: }{json}{"\n\n"}
 * JSON shape: {@code { "type": string, "data": object }}
 *
 * Bump {@see StudioGenerationSseProtocol::VERSION} when breaking clients.
 *
 * Event types (v1):
 * - text_delta — data: messageId (string), content (string fragment of raw model output)
 * - done       — data: outlines (list), raw (optional string), parseError (optional bool)
 * - error      — data: message (string), errorCode (optional string)
 */
final class StudioGenerationSseProtocol
{
    public const int VERSION = 1;

    public const string TYPE_TEXT_DELTA = 'text_delta';

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
