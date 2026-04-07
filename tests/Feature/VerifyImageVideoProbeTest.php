<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerifyImageVideoProbeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function verify_image_succeeds_when_models_endpoint_accepts_key(): void
    {
        config([
            'tutor.image_generation.api_key' => 'sk-test',
            'tutor.image_generation.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::fake([
            'api.openai.com/v1/models*' => Http::response(['data' => [['id' => 'gpt-4o-mini']]], 200),
        ]);

        $this->postJson('/api/verify/image-provider', [])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('probe', 'GET /v1/models');
    }

    #[Test]
    public function verify_image_falls_back_to_chat_when_models_not_available(): void
    {
        config([
            'tutor.image_generation.api_key' => 'sk-test',
            'tutor.image_generation.base_url' => 'https://api.example.com/v1',
        ]);

        Http::fake([
            'api.example.com/v1/models*' => Http::response(['error' => 'not found'], 404),
            'api.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'x']]],
            ], 200),
        ]);

        $this->postJson('/api/verify/image-provider', [])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('probe', 'POST /v1/chat/completions');
    }

    #[Test]
    public function verify_video_succeeds_on_minimax_parameter_error(): void
    {
        config([
            'tutor.video_generation.api_key' => 'mm-test',
            'tutor.video_generation.base_url' => 'https://api.minimax.io',
        ]);

        Http::fake([
            'api.minimax.io/v1/video_generation' => Http::response([
                'base_resp' => ['status_code' => 2013, 'status_msg' => 'Invalid params'],
            ], 200),
        ]);

        $this->postJson('/api/verify/video-provider', [])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('probe', 'POST /v1/video_generation (parameter check)');
    }

    #[Test]
    public function verify_video_fails_on_minimax_auth_error(): void
    {
        config([
            'tutor.video_generation.api_key' => 'bad',
            'tutor.video_generation.base_url' => 'https://api.minimax.io',
        ]);

        Http::fake([
            'api.minimax.io/v1/video_generation' => Http::response([
                'base_resp' => ['status_code' => 1004, 'status_msg' => 'auth failed'],
            ], 200),
        ]);

        $this->postJson('/api/verify/video-provider', [])
            ->assertOk()
            ->assertJsonPath('ok', false);
    }

    #[Test]
    public function generate_routes_respect_low_throttle_limit(): void
    {
        config([
            'tutor.generate.throttle_per_minute' => 2,
            'tutor.default_chat.api_key' => 'sk-test',
            'tutor.default_chat.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"actions":[]}' ]]],
            ], 200),
        ]);

        $payload = [
            'sceneTitle' => 'A',
            'instruction' => 'B',
            'requiresApiKey' => false,
        ];

        try {
            $this->postJson('/api/generate/scene-actions', $payload)->assertOk();
            $this->postJson('/api/generate/scene-actions', $payload)->assertOk();
            $this->postJson('/api/generate/scene-actions', $payload)->assertStatus(429);
        } finally {
            Cache::flush();
        }
    }
}
