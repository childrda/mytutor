<?php

namespace Tests\Unit;

use App\Support\TutorDefaultChatRuntime;
use Tests\TestCase;

class TutorDefaultChatRuntimeTest extends TestCase
{
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
}
