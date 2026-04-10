<?php

namespace Tests\Unit;

use App\Services\Ai\StatelessChatStreamer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StatelessChatStreamerRegistryTest extends TestCase
{
    #[Test]
    public function streaming_uses_registry_resolved_url_when_active_llm_openai(): void
    {
        config([
            'tutor.active.llm' => 'openai',
            'tutor.chat_tools.tools' => [],
        ]);

        $delta = json_encode(['choices' => [['delta' => ['content' => 'Hi']]]], JSON_THROW_ON_ERROR);
        $sse = "data: {$delta}\n\n"."data: [DONE]\n\n";

        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sse),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $client = new Client(['handler' => $stack, 'timeout' => 10, 'connect_timeout' => 5]);

        $streamer = new StatelessChatStreamer($client);
        $emitted = '';
        $streamer->stream(
            'https://api.test/v1',
            'sk-x',
            'gpt-4o-mini',
            [['role' => 'user', 'content' => 'yo']],
            'Preamble',
            ['tutor'],
            function (string $chunk) use (&$emitted): void {
                $emitted .= $chunk;
            },
            [],
            [],
        );

        $this->assertNotEmpty($emitted);
        $this->assertCount(1, $container);
        $req = $container[0]['request'];
        $this->assertStringContainsString('api.test/v1/chat/completions', (string) $req->getUri());
        $this->assertSame('Bearer sk-x', $req->getHeaderLine('Authorization'));
        $this->assertSame('text/event-stream', $req->getHeaderLine('Accept'));

        $body = json_decode((string) $req->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($body['stream'] ?? false);
        $this->assertSame('gpt-4o-mini', $body['model'] ?? null);
    }
}
