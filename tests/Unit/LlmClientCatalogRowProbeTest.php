<?php

namespace Tests\Unit;

use App\Services\Ai\LlmClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LlmClientCatalogRowProbeTest extends TestCase
{
    #[Test]
    public function uses_openai_row_endpoint_even_when_active_llm_is_different(): void
    {
        config(['tutor.active.llm' => 'google', 'tutor.llm_completion_limit_param' => 'max_completion_tokens']);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '.']]],
            ], 200),
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => 'wrong host'], 500),
        ]);

        $entry = [
            'provider' => 'openai',
            'endpoint' => '{base_url}/chat/completions',
            'request_format' => [
                'model' => '{model|gpt-4o-mini}',
                'messages' => '{messages}',
                'temperature' => '{temperature|0.7}',
                'max_completion_tokens' => '{max_completion_tokens|2048}',
            ],
            'response_path' => 'choices[0].message.content',
            'auth_header' => 'Authorization',
            'auth_scheme' => 'Bearer {api_key}',
            'base_url' => 'https://api.openai.com/v1',
        ];

        $out = LlmClient::verifyLlmCatalogRowProbe($entry, 'sk-row-probe', 'https://api.openai.com/v1', 'gpt-4o-mini', 15.0);

        $this->assertSame(['ok' => true], $out);
        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), 'api.openai.com/v1/chat/completions');
        });
    }
}
