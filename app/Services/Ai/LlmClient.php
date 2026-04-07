<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

/**
 * OpenAI-compatible chat completion helper (streaming and non-streaming).
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

    /**
     * Chat with arbitrary message shapes (e.g. multimodal user content for vision models).
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public static function chatWithMessages(
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
            ->timeout(300)
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

    /**
     * Stream chat completion deltas (OpenAI-style SSE). Yields assistant content fragments only.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return \Generator<int, string, mixed, void>
     */
    public static function streamChat(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        ?float $temperature = 0.3,
        ?int $maxTokens = 2048,
    ): \Generator {
        $url = rtrim($baseUrl, '/').'/chat/completions';

        $response = Http::withToken($apiKey, 'Bearer')
            ->acceptJson()
            ->timeout(300)
            ->withOptions([
                'stream' => true,
                'read_timeout' => 300,
            ])
            ->post($url, [
                'model' => $model,
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'stream' => true,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('LLM stream request failed: '.$response->body());
        }

        $stream = $response->toPsrResponse()->getBody();
        $carry = '';

        while (! $stream->eof()) {
            $chunk = $stream->read(4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $carry .= $chunk;

            while (($pos = strpos($carry, "\n")) !== false) {
                $line = substr($carry, 0, $pos);
                $carry = substr($carry, $pos + 1);
                $line = rtrim($line, "\r");
                if ($line === '' || str_starts_with($line, ':')) {
                    continue;
                }
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }
                $payload = trim(substr($line, strlen('data:')));
                if ($payload === '' || $payload === '[DONE]') {
                    if ($payload === '[DONE]') {
                        return;
                    }

                    continue;
                }

                try {
                    $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    continue;
                }

                if (! is_array($data)) {
                    continue;
                }

                $delta = $data['choices'][0]['delta']['content'] ?? null;
                if (is_string($delta) && $delta !== '') {
                    yield $delta;
                }
            }
        }
    }
}
