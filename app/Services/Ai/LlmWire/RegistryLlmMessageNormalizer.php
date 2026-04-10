<?php

namespace App\Services\Ai\LlmWire;

/**
 * Adapts caller-side OpenAI-style chat messages into registry template variables (outside {@see ModelRegistryHttpExecutor}).
 */
final class RegistryLlmMessageNormalizer
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, mixed>|null Template vars merged with api_key, base_url, model, temperature, timeout
     */
    public static function templateVars(LlmInputWire $wire, array $messages): ?array
    {
        return match ($wire) {
            LlmInputWire::OpenAiChatCompletions => [
                'messages' => $messages,
                'system' => '',
                'contents' => [],
            ],
            LlmInputWire::AnthropicMessages => self::forAnthropic($messages),
            LlmInputWire::GoogleGenerateContent => self::forGoogle($messages),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, mixed>|null
     */
    private static function forAnthropic(array $messages): ?array
    {
        $systemChunks = [];
        $dialogue = [];

        foreach ($messages as $m) {
            if (! is_array($m)) {
                return null;
            }
            $role = isset($m['role']) && is_string($m['role']) ? strtolower(trim($m['role'])) : '';
            $content = $m['content'] ?? null;
            if ($role === 'system') {
                if (! is_string($content)) {
                    return null;
                }
                $t = trim($content);
                if ($t !== '') {
                    $systemChunks[] = $t;
                }

                continue;
            }
            if ($role !== 'user' && $role !== 'assistant') {
                return null;
            }
            if (! is_string($content)) {
                return null;
            }

            $dialogue[] = ['role' => $role, 'content' => $content];
        }

        return [
            'messages' => $dialogue,
            'system' => implode("\n\n", $systemChunks),
            'contents' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, mixed>|null
     */
    private static function forGoogle(array $messages): ?array
    {
        $systemChunks = [];
        $contents = [];

        foreach ($messages as $m) {
            if (! is_array($m)) {
                return null;
            }
            $role = isset($m['role']) && is_string($m['role']) ? strtolower(trim($m['role'])) : '';
            $content = $m['content'] ?? null;
            if ($role === 'system') {
                if (! is_string($content)) {
                    return null;
                }
                $t = trim($content);
                if ($t !== '') {
                    $systemChunks[] = $t;
                }

                continue;
            }
            if ($role !== 'user' && $role !== 'assistant') {
                return null;
            }
            if (! is_string($content)) {
                return null;
            }

            $geminiRole = $role === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $geminiRole,
                'parts' => [['text' => $content]],
            ];
        }

        return [
            'messages' => [],
            'system' => implode("\n\n", $systemChunks),
            'contents' => $contents,
        ];
    }
}
