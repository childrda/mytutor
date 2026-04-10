<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerifyModelProbeTest extends TestCase
{
    #[Test]
    public function verify_model_succeeds_on_legacy_path_when_active_llm_unset(): void
    {
        config(['tutor.active.llm' => null]);

        Http::fake([
            'https://api.custom/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'ok']]],
            ], 200),
        ]);

        $this->postJson('/api/verify/model', [
            'baseUrl' => 'https://api.custom/v1',
            'apiKey' => 'sk-ui',
            'model' => 'gpt-4o-mini',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    #[Test]
    public function verify_model_uses_registry_when_active_openai_llm(): void
    {
        config([
            'tutor.active.llm' => 'openai',
            'tutor.llm_completion_limit_param' => 'max_completion_tokens',
        ]);

        Http::fake([
            'https://api.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'x']]],
            ], 200),
        ]);

        $this->postJson('/api/verify/model', [
            'baseUrl' => 'https://api.test/v1',
            'apiKey' => 'sk-reg',
            'model' => 'gpt-4o-mini',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.test/v1/chat/completions')
                && $request->hasHeader('Authorization', 'Bearer sk-reg');
        });
    }
}
