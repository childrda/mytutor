<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\StatelessChatStreamer;
use App\Support\Chat\ChatSseProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /api/chat validation and error responses (Phase 3.5). Uses DB for Sanctum session auth (Phase 6).
 */
class ChatResilienceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validSseChatBody(array $overrides = []): array
    {
        return array_merge([
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'storeState' => ['lessonId' => 't1', 'lessonName' => 'Test'],
            'config' => ['agentIds' => ['tutor']],
            'requiresApiKey' => false,
        ], $overrides);
    }

    #[Test]
    public function missing_messages_returns_400_json(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/chat', [
            'storeState' => ['lessonId' => 'x'],
            'config' => ['agentIds' => ['tutor']],
            'requiresApiKey' => false,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errorCode', 'MISSING_REQUIRED_FIELD');
    }

    #[Test]
    public function missing_store_state_returns_400_json(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/chat', [
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'config' => ['agentIds' => ['tutor']],
            'requiresApiKey' => false,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('errorCode', 'MISSING_REQUIRED_FIELD');
    }

    #[Test]
    public function missing_api_key_returns_401_json_not_sse(): void
    {
        config([
            'tutor.default_chat.api_key' => '',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/chat', $this->validSseChatBody([
            'requiresApiKey' => true,
        ]));

        $response->assertStatus(401)
            ->assertJsonPath('errorCode', 'MISSING_API_KEY');
        $this->assertStringNotContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function no_text_content_in_messages_returns_400(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/chat', $this->validSseChatBody([
            'messages' => [
                ['role' => 'user', 'parts' => [['type' => 'image', 'url' => 'x']]],
            ],
        ]));

        $response->assertStatus(400)
            ->assertJsonPath('errorCode', 'MISSING_MESSAGES');
    }

    #[Test]
    public function too_many_messages_returns_400(): void
    {
        config(['tutor.chat_stream.max_messages' => 2]);

        $response = $this->actingAs($this->user)->postJson('/api/chat', $this->validSseChatBody([
            'messages' => [
                ['role' => 'user', 'content' => 'a'],
                ['role' => 'user', 'content' => 'b'],
                ['role' => 'user', 'content' => 'c'],
            ],
        ]));

        $response->assertStatus(400)
            ->assertJsonPath('errorCode', 'MESSAGE_LIMIT');
    }

    #[Test]
    public function streamed_success_includes_protocol_version_and_done(): void
    {
        $this->mock(StatelessChatStreamer::class, function ($mock): void {
            $mock->shouldReceive('stream')
                ->once()
                ->andReturnUsing(function (
                    string $baseUrl,
                    string $apiKey,
                    string $model,
                    array $messages,
                    string $systemPreamble,
                    array $agentIds,
                    \Closure $emit,
                    array $directorStateBaseline = [],
                ): void {
                    $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_DONE, [
                        'totalActions' => 0,
                        'totalAgents' => 1,
                        'agentHadContent' => true,
                        'directorState' => [
                            'turnCount' => 0,
                            'agentResponses' => [],
                            'whiteboardLedger' => [],
                        ],
                    ]));
                });
        });

        $response = $this->actingAs($this->user)->call('POST', '/api/chat', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/event-stream',
        ], json_encode($this->validSseChatBody(), JSON_THROW_ON_ERROR));

        $response->assertOk();
        $response->assertHeader('X-Chat-Protocol-Version', (string) ChatSseProtocol::VERSION);
        $this->assertStringContainsString('"type":"'.ChatSseProtocol::TYPE_DONE.'"', $response->streamedContent());
    }
}
