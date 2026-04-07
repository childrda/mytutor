<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Non-streaming OpenAI-compatible chat completion helper.
 */
final class LlmClient
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public static function chat(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        ?float $temperature = 0.3,
        ?int $maxTokens = 2048,
    ): string {
        $url = rtrim($baseUrl, '/').'/chat/completions';
        $res = Http::withToken($apiKey, 'Bearer')
            ->acceptJson()
            ->timeout(120)
            ->post($url, [
                'model' => $model,
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);

        if (! $res->successful()) {
            throw new RuntimeException('LLM request failed: '.$res->body());
        }

        $data = $res->json();
        $content = data_get($data, 'choices.0.message.content');
        if (! is_string($content)) {
            throw new RuntimeException('LLM returned no text content.');
        }

        return $content;
    }
}
