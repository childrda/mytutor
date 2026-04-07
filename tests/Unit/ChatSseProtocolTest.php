<?php

namespace Tests\Unit;

use App\Support\Chat\ChatSseProtocol;
use PHPUnit\Framework\TestCase;

class ChatSseProtocolTest extends TestCase
{
    public function test_frame_outputs_data_line_with_type_and_data_objects(): void
    {
        $line = ChatSseProtocol::frame(ChatSseProtocol::TYPE_TEXT_DELTA, [
            'messageId' => '01hx',
            'content' => 'Hi',
        ]);

        $this->assertStringStartsWith('data: ', $line);
        $this->assertStringEndsWith("\n\n", $line);
        $json = trim(substr($line, strlen('data: ')));
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(ChatSseProtocol::TYPE_TEXT_DELTA, $decoded['type']);
        $this->assertIsArray($decoded['data']);
        $this->assertSame('01hx', $decoded['data']['messageId']);
        $this->assertSame('Hi', $decoded['data']['content']);
    }
}
