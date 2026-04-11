<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ApiJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StudioSceneGenerationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function scene_actions_requires_api_key_when_required(): void
    {
        config(['tutor.default_chat.api_key' => '']);

        $this->actingAs(User::factory()->create())->postJson('/api/generate/scene-actions', [
            'sceneTitle' => 'Intro',
            'instruction' => 'Suggest edits',
            'requiresApiKey' => true,
        ])
            ->assertStatus(401)
            ->assertJsonPath('errorCode', ApiJson::MISSING_API_KEY);
    }

    #[Test]
    public function scene_actions_returns_normalized_actions(): void
    {
        config([
            'tutor.default_chat.api_key' => 'sk-test',
            'tutor.default_chat.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"actions":[{"id":"add-title","label":"Add title","kind":"edit"}]}',
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs(User::factory()->create())->postJson('/api/generate/scene-actions', [
            'sceneTitle' => 'Intro',
            'instruction' => 'Suggest next steps',
            'requiresApiKey' => false,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('actions.0.id', 'add-title')
            ->assertJsonPath('actions.0.label', 'Add title');
    }

    #[Test]
    public function scene_content_requires_scene_title(): void
    {
        config(['tutor.default_chat.api_key' => 'sk-test']);

        $this->actingAs(User::factory()->create())->postJson('/api/generate/scene-content', [
            'instruction' => 'Write body',
            'requiresApiKey' => false,
        ])
            ->assertStatus(400)
            ->assertJsonPath('errorCode', ApiJson::MISSING_REQUIRED_FIELD);
    }

    #[Test]
    public function agent_profiles_returns_agents(): void
    {
        config([
            'tutor.default_chat.api_key' => 'sk-test',
            'tutor.default_chat.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"agents":[{"id":"coach-a","name":"Coach","persona":"Guides with questions."}]}',
                    ],
                ]],
            ], 200),
        ]);

        $this->postJson('/api/generate/agent-profiles', [
            'instruction' => 'Define two personas',
            'requiresApiKey' => false,
        ])
            ->assertOk()
            ->assertJsonPath('agents.0.id', 'coach-a');
    }

    #[Test]
    public function scene_outlines_stream_emits_sse_frames(): void
    {
        config([
            'tutor.default_chat.api_key' => 'sk-test',
            'tutor.default_chat.base_url' => 'https://api.openai.com/v1',
            'tutor.studio_generation.sse_chunk_chars' => 40,
        ]);

        $json = '{"outlines":[{"title":"One","type":"slide","summary":"S"}]}';

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => $json],
                ]],
            ], 200),
        ]);

        $response = $this->actingAs(User::factory()->create())->postJson('/api/generate/scene-outlines-stream', [
            'instruction' => 'Outline the lesson',
            'requiresApiKey' => false,
        ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'text/event-stream; charset=UTF-8');

        $body = $response->streamedContent();
        $this->assertStringContainsString('"type":"text_delta"', $body);
        $this->assertStringContainsString('"type":"done"', $body);
        $this->assertStringContainsString('"parseError":false', $body);
        $this->assertStringContainsString('One', $body);
    }
}
