<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\StatelessChatStreamer;
use App\Support\Chat\ChatSseProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatStreamProtocolTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function streamed_chat_response_includes_protocol_version_header(): void
    {
        $user = User::factory()->create();
        $capturedPreamble = null;
        $capturedDirector = null;

        $this->mock(StatelessChatStreamer::class, function ($mock) use (&$capturedPreamble, &$capturedDirector): void {
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
                ) use (&$capturedPreamble, &$capturedDirector): void {
                    $capturedPreamble = $systemPreamble;
                    $capturedDirector = $directorStateBaseline;
                    $emit(ChatSseProtocol::frame(ChatSseProtocol::TYPE_DONE, [
                        'totalActions' => 0,
                        'totalAgents' => 1,
                        'agentHadContent' => true,
                        'directorState' => ['turnCount' => 1],
                    ]));
                });
        });

        $response = $this->actingAs($user)->call('POST', '/api/chat', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/event-stream',
        ], json_encode([
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'storeState' => [
                'lessonId' => 'test',
                'lessonName' => 'Demo',
                'scene' => [
                    'id' => 'scene-a',
                    'title' => 'Scene A',
                    'type' => 'qa',
                    'contentSummary' => '{}',
                ],
            ],
            'config' => ['agentIds' => ['tutor'], 'sessionType' => 'discussion'],
            'directorState' => [
                'turnCount' => 1,
                'agentResponses' => [],
                'whiteboardLedger' => [['op' => 'demo']],
            ],
            'requiresApiKey' => false,
        ], JSON_THROW_ON_ERROR));

        $response->assertOk();
        $response->assertHeader('X-Chat-Protocol-Version', (string) ChatSseProtocol::VERSION);
        $this->assertStringContainsString('"type":"'.ChatSseProtocol::TYPE_DONE.'"', $response->streamedContent());
        $this->assertIsString($capturedPreamble);
        $this->assertStringContainsString('## Lesson context', $capturedPreamble);
        $this->assertStringContainsString('Scene A', $capturedPreamble);
        $this->assertStringContainsString('discussion', $capturedPreamble);
        $this->assertIsArray($capturedDirector);
        $this->assertSame(1, $capturedDirector['turnCount']);
        $this->assertSame([['op' => 'demo']], $capturedDirector['whiteboardLedger']);
    }
}
