<?php

namespace Tests\Unit;

use App\Services\Ai\LlmClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LlmClientVerifyChatCompletionsPingTest extends TestCase
{
    #[Test]
    public function uses_registry_executor_when_active_openai_llm(): void
    {
        config([
            'tutor.active.llm' => 'openai',
            'tutor.llm_completion_limit_param' => 'max_completion_tokens',
        ]);

        Http::fake([
            'https://api.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '.']]],
            ], 200),
        ]);

        $out = LlmClient::verifyChatCompletionsPing(
            'https://api.test/v1',
            'sk-verify',
            'gpt-4o-mini',
            25.0,
        );

        $this->assertSame(['ok' => true], $out);
        Http::assertSent(function ($request) {
            $d = $request->data();

            return str_contains($request->url(), 'api.test/v1/chat/completions')
                && ($d['model'] ?? null) === 'gpt-4o-mini'
                && ($d['max_completion_tokens'] ?? null) === 1
                && $request->hasHeader('Authorization', 'Bearer sk-verify');
        });
    }

    #[Test]
    public function without_active_llm_uses_legacy_post(): void
    {
        config(['tutor.active.llm' => null]);

        Http::fake([
            'https://legacy.example/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'pong']]],
            ], 200),
        ]);

        $out = LlmClient::verifyChatCompletionsPing(
            'https://legacy.example/v1',
            'sk-leg',
            'm-mini',
            30.0,
        );

        $this->assertSame(['ok' => true], $out);
        Http::assertSent(function ($request) {
            $d = $request->data();

            return str_contains($request->url(), 'legacy.example/v1/chat/completions')
                && ($d['model'] ?? null) === 'm-mini'
                && ! array_key_exists('temperature', $d)
                && $request->hasHeader('Authorization', 'Bearer sk-leg');
        });
    }

    #[Test]
    public function registry_path_returns_status_on_http_error(): void
    {
        config(['tutor.active.llm' => 'openai']);

        Http::fake([
            'https://api.test/v1/chat/completions' => Http::response(['error' => 'nope'], 401),
        ]);

        $out = LlmClient::verifyChatCompletionsPing(
            'https://api.test/v1',
            'sk-bad',
            'gpt-4o-mini',
            20.0,
        );

        $this->assertFalse($out['ok']);
        $this->assertSame(401, $out['status'] ?? null);
        $this->assertArrayHasKey('body', $out);
    }

    #[Test]
    public function integration_probe_skips_registry_and_errors_when_base_url_empty(): void
    {
        config(['tutor.active.llm' => 'openai']);

        $out = LlmClient::verifyChatCompletionsPing('', 'sk-any', 'gpt-4o-mini', 10.0, false);

        $this->assertFalse($out['ok']);
        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsString('Missing base URL', (string) ($out['error'] ?? ''));
    }
}
