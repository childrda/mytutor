<?php

namespace Tests\Unit;

use App\Services\Ai\LlmClient;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LlmClientResolveActiveRegistryModelTest extends TestCase
{
    protected function tearDown(): void
    {
        config(['tutor.active.llm' => null]);

        parent::tearDown();
    }

    #[Test]
    public function returns_null_when_no_active_llm(): void
    {
        config(['tutor.active.llm' => null]);

        $this->assertNull(LlmClient::resolveActiveRegistryModel());
    }

    #[Test]
    public function returns_literal_request_format_model_for_openai_gpt_4o_row(): void
    {
        config(['tutor.active.llm' => 'openai-gpt-4o']);

        $this->assertSame('gpt-4o', LlmClient::resolveActiveRegistryModel());
    }

    #[Test]
    public function model_pipe_prefers_default_chat_model_config(): void
    {
        config([
            'tutor.active.llm' => 'openai',
            'tutor.default_chat.model' => 'user-default-llm-model',
        ]);

        $this->assertSame('user-default-llm-model', LlmClient::resolveActiveRegistryModel());
    }

    #[Test]
    public function model_pipe_uses_template_default_when_default_chat_model_empty(): void
    {
        config([
            'tutor.active.llm' => 'openai',
            'tutor.default_chat.model' => '',
        ]);

        $this->assertSame('gpt-4o-mini', LlmClient::resolveActiveRegistryModel());
    }

    #[Test]
    public function bare_model_placeholder_returns_null(): void
    {
        config(['tutor.active.llm' => 'qwen']);

        $this->assertNull(LlmClient::resolveActiveRegistryModel());
    }
}
