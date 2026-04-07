<?php

namespace App\Support\Chat;

/**
 * Validates mapped OpenAI-style messages for POST /api/chat (Phase 3.5).
 */
final class TutorChatRequestLimits
{
    /**
     * @param  list<array{role: string, content: string}>  $openAiMessages
     * @return array{ok: true}|array{ok: false, error: string, errorCode: string}
     */
    public static function validateMappedMessages(array $openAiMessages): array
    {
        $maxN = max(1, (int) config('tutor.chat_stream.max_messages', 200));
        if (count($openAiMessages) > $maxN) {
            return [
                'ok' => false,
                'error' => 'Too many messages (max '.$maxN.')',
                'errorCode' => 'MESSAGE_LIMIT',
            ];
        }

        if ($openAiMessages === []) {
            return [
                'ok' => false,
                'error' => 'At least one message with text content is required',
                'errorCode' => 'MISSING_MESSAGES',
            ];
        }

        $maxBytes = max(1, (int) config('tutor.chat_stream.max_total_content_bytes', 500_000));
        $total = 0;
        foreach ($openAiMessages as $m) {
            $total += strlen($m['content'] ?? '');
            if ($total > $maxBytes) {
                return [
                    'ok' => false,
                    'error' => 'Total message content exceeds limit ('.$maxBytes.' bytes)',
                    'errorCode' => 'CONTENT_TOO_LARGE',
                ];
            }
        }

        return ['ok' => true];
    }

    /**
     * @param  list<mixed>  $rawMessages
     */
    public static function validateRawMessageCount(array $rawMessages): array
    {
        $maxN = max(1, (int) config('tutor.chat_stream.max_messages', 200));
        if (count($rawMessages) > $maxN) {
            return [
                'ok' => false,
                'error' => 'Too many messages (max '.$maxN.')',
                'errorCode' => 'MESSAGE_LIMIT',
            ];
        }

        return ['ok' => true];
    }
}
