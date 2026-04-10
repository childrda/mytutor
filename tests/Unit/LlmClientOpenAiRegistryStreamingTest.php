<?php

namespace Tests\Unit;

use App\Services\Ai\LlmClient;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LlmClientOpenAiRegistryStreamingTest extends TestCase
{
    #[Test]
    public function open_ai_registry_chat_endpoint_and_headers_returns_url_and_bearer_when_active(): void
    {
        config(['tutor.active.llm' => 'openai']);

        $out = LlmClient::openAiRegistryChatEndpointAndHeaders(
            'https://api.test/v1',
            'sk-stream',
            'gpt-4o-mini',
            true,
        );

        $this->assertNotNull($out);
        $this->assertSame('https://api.test/v1/chat/completions', $out['url']);
        $this->assertSame('Bearer sk-stream', $out['headers']['Authorization'] ?? '');
        $this->assertSame('application/json', $out['headers']['Content-Type'] ?? '');
        $this->assertSame('text/event-stream', $out['headers']['Accept'] ?? '');
    }

    #[Test]
    public function open_ai_registry_chat_endpoint_and_headers_uses_json_accept_when_not_streaming(): void
    {
        config(['tutor.active.llm' => 'openai']);

        $out = LlmClient::openAiRegistryChatEndpointAndHeaders(
            'https://api.test/v1',
            'sk-pool',
            'gpt-4o-mini',
            false,
        );

        $this->assertNotNull($out);
        $this->assertSame('application/json', $out['headers']['Accept'] ?? '');
    }

    #[Test]
    public function returns_null_when_active_llm_not_set(): void
    {
        config(['tutor.active.llm' => null]);

        $this->assertNull(LlmClient::openAiRegistryChatEndpointAndHeaders(
            'https://api.test/v1',
            'sk',
            'm',
        ));
    }

    #[Test]
    public function returns_null_for_anthropic_active_entry(): void
    {
        config(['tutor.active.llm' => 'anthropic']);

        $this->assertNull(LlmClient::openAiRegistryChatEndpointAndHeaders(
            'https://api.anthropic.com/v1',
            'sk-ant',
            'claude-3-5-sonnet-20241022',
        ));
    }
}
