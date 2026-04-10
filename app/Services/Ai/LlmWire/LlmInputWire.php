<?php

namespace App\Services\Ai\LlmWire;

/**
 * How OpenAI-shaped caller messages map into the active registry LLM entry (Phase B7).
 * Resolved from {@see config/models.json} {@code llm_input_wire} or {@code response_path}.
 */
enum LlmInputWire: string
{
    case OpenAiChatCompletions = 'openai_chat_completions';
    case AnthropicMessages = 'anthropic_messages';
    case GoogleGenerateContent = 'google_generate_content';

    /**
     * @param  array<string, mixed>  $entry  Registry llm.* provider object
     */
    public static function fromEntry(array $entry): self
    {
        $override = $entry['llm_input_wire'] ?? null;
        if (is_string($override) && $override !== '') {
            $w = self::tryFrom($override);
            if ($w !== null) {
                return $w;
            }
        }

        $rp = isset($entry['response_path']) ? trim((string) $entry['response_path']) : '';

        if ($rp === 'content[0].text') {
            return self::AnthropicMessages;
        }

        if (str_contains($rp, 'candidates') && str_contains($rp, 'parts')) {
            return self::GoogleGenerateContent;
        }

        return self::OpenAiChatCompletions;
    }
}
