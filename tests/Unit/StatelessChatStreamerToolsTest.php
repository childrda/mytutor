<?php

namespace Tests\Unit;

use App\Services\Ai\StatelessChatStreamer;
use App\Support\Chat\ChatSseProtocol;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StatelessChatStreamerToolsTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $chunks  Raw OpenAI-style choice objects (one delta per chunk)
     */
    private function sseFromChoiceChunks(array $chunks): Response
    {
        $body = '';
        foreach ($chunks as $choice) {
            $body .= 'data: '.json_encode(['choices' => [$choice]], JSON_THROW_ON_ERROR)."\n\n";
        }
        $body .= "data: [DONE]\n\n";

        return new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($body));
    }

    private function toolCallStreamWhiteboard(): Response
    {
        return $this->sseFromChoiceChunks([
            ['delta' => [
                'tool_calls' => [[
                    'index' => 0,
                    'id' => 'call_tooltest',
                    'type' => 'function',
                    'function' => ['name' => 'whiteboard_append', 'arguments' => ''],
                ]],
            ]],
            ['delta' => [
                'tool_calls' => [[
                    'index' => 0,
                    'function' => ['arguments' => '{"label":"n1"}'],
                ]],
            ]],
            ['finish_reason' => 'tool_calls'],
        ]);
    }

    private function textStream(string $text): Response
    {
        return $this->sseFromChoiceChunks([
            ['delta' => ['content' => $text]],
        ]);
    }

    #[Test]
    public function tool_round_emits_action_then_text_from_follow_up_completion(): void
    {
        $mock = new MockHandler([
            $this->toolCallStreamWhiteboard(),
            $this->textStream('Done.'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $streamer = new StatelessChatStreamer($client);
        $lines = [];
        $streamer->stream(
            'https://api.example.com/v1',
            'k',
            'm',
            [['role' => 'user', 'content' => 'Q']],
            'Preamble.',
            ['tutor'],
            function (string $chunk) use (&$lines): void {
                $lines[] = $chunk;
            },
        );

        $joined = implode('', $lines);
        $this->assertStringContainsString('"type":"'.ChatSseProtocol::TYPE_ACTION.'"', $joined);
        $this->assertStringContainsString('"tool":"whiteboard_append"', $joined);
        $this->assertStringContainsString('"totalActions":1', $joined);
        $this->assertStringContainsString('Done.', $joined);
        $this->assertStringContainsString('"type":"'.ChatSseProtocol::TYPE_DONE.'"', $joined);
    }

    #[Test]
    public function unknown_tool_name_emits_error_without_done(): void
    {
        $badTool = $this->sseFromChoiceChunks([
            ['delta' => [
                'tool_calls' => [[
                    'index' => 0,
                    'id' => 'call_x',
                    'type' => 'function',
                    'function' => ['name' => 'totally.unknown.tool', 'arguments' => '{}'],
                ]],
            ]],
            ['finish_reason' => 'tool_calls'],
        ]);

        $mock = new MockHandler([$badTool]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $streamer = new StatelessChatStreamer($client);
        $lines = [];
        $streamer->stream(
            'https://api.example.com/v1',
            'k',
            'm',
            [['role' => 'user', 'content' => 'Q']],
            'Preamble.',
            ['tutor'],
            function (string $chunk) use (&$lines): void {
                $lines[] = $chunk;
            },
        );

        $joined = implode('', $lines);
        $this->assertStringContainsString('"type":"'.ChatSseProtocol::TYPE_ERROR.'"', $joined);
        $this->assertStringContainsString('Unknown tool', $joined);
        $this->assertStringNotContainsString('"type":"'.ChatSseProtocol::TYPE_DONE.'"', $joined);
    }
}
