<?php

namespace Tests\Unit;

use App\Support\TutorDefaultChatRuntime;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TutorDefaultChatRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        config([
            'tutor.active.llm' => null,
            'tutor.default_chat.api_key' => '',
        ]);
        putenv('OPENAI_API_KEY');
        putenv('TUTOR_DEFAULT_LLM_API_KEY');

        parent::tearDown();
    }

    public function test_prefers_non_empty_config_over_env(): void
    {
        config(['tutor.default_chat.api_key' => 'sk-from-config']);
        putenv('OPENAI_API_KEY=sk-from-env');

        $this->assertSame('sk-from-config', TutorDefaultChatRuntime::apiKey());

        putenv('OPENAI_API_KEY');
    }

    public function test_prefers_tutor_default_llm_key_over_openai(): void
    {
        config(['tutor.default_chat.api_key' => '']);
        putenv('TUTOR_DEFAULT_LLM_API_KEY=sk-dedicated');
        putenv('OPENAI_API_KEY=sk-openai');

        $this->assertSame('sk-dedicated', TutorDefaultChatRuntime::apiKey());

        putenv('TUTOR_DEFAULT_LLM_API_KEY');
        putenv('OPENAI_API_KEY');
    }

    #[Test]
    public function resolved_wire_base_url_uses_body_when_non_empty(): void
    {
        config(['tutor.active.llm' => 'openai']);
        $this->assertSame(
            'https://custom.example/v1',
            TutorDefaultChatRuntime::resolvedWireBaseUrl('https://custom.example/v1/')
        );
    }

    #[Test]
    public function resolved_wire_base_url_empty_body_with_active_llm_returns_empty_string(): void
    {
        config(['tutor.active.llm' => 'openai', 'tutor.default_chat.base_url' => 'https://legacy.example/v1']);
        $this->assertSame('', TutorDefaultChatRuntime::resolvedWireBaseUrl(null));
    }

    #[Test]
    public function resolved_wire_base_url_empty_body_without_active_uses_config(): void
    {
        config(['tutor.active.llm' => null, 'tutor.default_chat.base_url' => 'https://legacy.example/v1/']);
        $this->assertSame('https://legacy.example/v1', TutorDefaultChatRuntime::resolvedWireBaseUrl(null));
    }

    #[Test]
    public function resolved_wire_model_uses_concrete_request_format_model_from_active_row(): void
    {
        config(['tutor.active.llm' => 'openai-gpt-4o', 'tutor.default_chat.model' => 'should-not-use']);
        $this->assertSame('gpt-4o', TutorDefaultChatRuntime::resolvedWireModel(null));
    }

    #[Test]
    public function resolved_wire_model_template_model_falls_back_to_default_chat_model(): void
    {
        config([
            'tutor.active.llm' => 'openai',
            'tutor.default_chat.model' => 'from-default-chat',
        ]);
        $this->assertSame('from-default-chat', TutorDefaultChatRuntime::resolvedWireModel(null));
    }

    #[Test]
    public function resolved_wire_api_key_empty_with_active_llm_returns_empty_string(): void
    {
        config(['tutor.active.llm' => 'openai']);
        $this->assertSame('', TutorDefaultChatRuntime::resolvedWireApiKey(''));
    }

    #[Test]
    public function resolved_wire_api_key_empty_without_active_falls_back_to_api_key_chain(): void
    {
        config([
            'tutor.default_chat.api_key' => 'sk-config-wire',
            'tutor.active.llm' => null,
        ]);

        $this->assertSame('sk-config-wire', TutorDefaultChatRuntime::resolvedWireApiKey(''));
    }
}
