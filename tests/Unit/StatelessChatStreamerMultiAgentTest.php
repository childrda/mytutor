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

class StatelessChatStreamerMultiAgentTest extends TestCase
{
    /**
     * @param  list<string>  $chunkContents
     */
    private function openAiSseResponse(array $chunkContents): Response
    {
        $body = '';
        foreach ($chunkContents as $c) {
            $body .= 'data: '.json_encode([
                'choices' => [['delta' => ['content' => $c]]],
            ], JSON_THROW_ON_ERROR)."\n\n";
        }
        $body .= "data: [DONE]\n\n";

        return new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($body));
    }

    #[Test]
    public function sequential_agents_emit_distinct_starts_and_done_total_agents(): void
    {
        $mock = new MockHandler([
            $this->openAiSseResponse(['A']),
            $this->openAiSseResponse(['B']),
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
            ['tutor', 'socratic'],
            function (string $chunk) use (&$lines): void {
                $lines[] = $chunk;
            },
        );

        $joined = implode('', $lines);
        $this->assertSame(2, substr_count($joined, '"type":"'.ChatSseProtocol::TYPE_AGENT_START.'"'));
        $this->assertStringContainsString('"totalAgents":2', $joined);
        $this->assertStringContainsString('"agentName":"Tutor"', $joined);
        $this->assertStringContainsString('"agentName":"Socratic guide"', $joined);
        $this->assertStringContainsString('"agentId":"tutor"', $joined);
        $this->assertStringContainsString('"agentId":"socratic"', $joined);
        $this->assertStringContainsString('"content":"A"', $joined);
        $this->assertStringContainsString('"content":"B"', $joined);
    }
}
