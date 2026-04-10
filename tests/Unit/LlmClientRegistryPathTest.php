<?php

namespace Tests\Unit;

use App\Services\Ai\LlmClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LlmClientRegistryPathTest extends TestCase
{
    #[Test]
    public function active_openai_llm_uses_registry_resolved_body_and_completion_limit(): void
    {
        config([
            'tutor.active.llm' => 'openai',
            'tutor.llm_completion_limit_param' => 'max_completion_tokens',
        ]);

        Http::fake([
            'https://api.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'ok']]],
            ], 200),
        ]);

        $text = LlmClient::chat(
            'https://api.test/v1',
            'sk-reg',
            'my-model',
            [['role' => 'user', 'content' => 'hello']],
            0.4,
            512,
            [],
        );

        $this->assertSame('ok', $text);
        Http::assertSent(function ($request) {
            $d = $request->data();

            return str_contains($request->url(), 'chat/completions')
                && ($d['model'] ?? null) === 'my-model'
                && ($d['temperature'] ?? null) === 0.4
                && ($d['max_completion_tokens'] ?? null) === 512
                && ! array_key_exists('max_tokens', $d)
                && $request->hasHeader('Authorization', 'Bearer sk-reg');
        });
    }

    #[Test]
    public function without_active_llm_legacy_path_unchanged(): void
    {
        config(['tutor.active.llm' => null]);

        Http::fake([
            'https://api.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'legacy']]],
            ], 200),
        ]);

        $text = LlmClient::chat(
            'https://api.test/v1',
            'sk-leg',
            'm',
            [['role' => 'user', 'content' => 'x']],
            0.3,
            10,
            [],
        );

        $this->assertSame('legacy', $text);
        Http::assertSent(function ($request) {
            $d = $request->data();

            return ($d['max_completion_tokens'] ?? null) === 10
                && $request->hasHeader('Authorization', 'Bearer sk-leg');
        });
    }

    #[Test]
    public function active_anthropic_uses_registry_messages_endpoint_and_x_api_key(): void
    {
        config([
            'tutor.active.llm' => 'anthropic',
            'tutor.llm_completion_limit_param' => 'max_completion_tokens',
        ]);

        Http::fake([
            'https://api.test/v1/messages' => Http::response([
                'content' => [['text' => 'via-anthropic']],
            ], 200),
        ]);

        $text = LlmClient::chat(
            'https://api.test/v1',
            'sk',
            'm',
            [['role' => 'user', 'content' => 'q']],
            0.3,
            2048,
            [],
        );

        $this->assertSame('via-anthropic', $text);
        Http::assertSent(function ($request) {
            $d = $request->data();

            return str_contains($request->url(), '/v1/messages')
                && $request->hasHeader('x-api-key', 'sk')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && ($d['model'] ?? null) === 'm'
                && ($d['messages'] ?? null) === [['role' => 'user', 'content' => 'q']]
                && ($d['max_tokens'] ?? null) === 2048
                && ($d['temperature'] ?? null) === 0.3
                && ! array_key_exists('system', $d);
        });
    }

    #[Test]
    public function active_google_uses_registry_generate_content_and_api_key_header(): void
    {
        config(['tutor.active.llm' => 'google']);

        Http::fake([
            'https://api.test/v1beta/models/gem:generateContent' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'via-gemini']],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $text = LlmClient::chat(
            'https://api.test',
            'gk',
            'gem',
            [['role' => 'user', 'content' => 'hi']],
            0.5,
            1024,
            [],
        );

        $this->assertSame('via-gemini', $text);
        Http::assertSent(function ($request) {
            $d = $request->data();

            return str_contains($request->url(), 'generateContent')
                && $request->hasHeader('X-Goog-Api-Key', 'gk')
                && ($d['contents'] ?? null) === [
                    ['role' => 'user', 'parts' => [['text' => 'hi']]],
                ]
                && ($d['generationConfig']['temperature'] ?? null) === 0.5
                && ($d['generationConfig']['maxOutputTokens'] ?? null) === 1024
                && ! array_key_exists('systemInstruction', $d);
        });
    }
}
