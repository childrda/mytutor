<?php

namespace Tests\Unit;

use App\Support\Chat\TutorChatRequestLimits;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TutorChatRequestLimitsTest extends TestCase
{
    #[Test]
    public function rejects_empty_mapped_messages(): void
    {
        $r = TutorChatRequestLimits::validateMappedMessages([]);
        $this->assertFalse($r['ok']);
        $this->assertSame('MISSING_MESSAGES', $r['errorCode']);
    }

    #[Test]
    public function rejects_when_message_count_exceeds_config(): void
    {
        config(['tutor.chat_stream.max_messages' => 2]);
        $r = TutorChatRequestLimits::validateMappedMessages([
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'user', 'content' => 'b'],
            ['role' => 'user', 'content' => 'c'],
        ]);
        $this->assertFalse($r['ok']);
        $this->assertSame('MESSAGE_LIMIT', $r['errorCode']);
    }

    #[Test]
    public function rejects_when_total_content_exceeds_config(): void
    {
        config(['tutor.chat_stream.max_total_content_bytes' => 10]);
        $r = TutorChatRequestLimits::validateMappedMessages([
            ['role' => 'user', 'content' => str_repeat('x', 11)],
        ]);
        $this->assertFalse($r['ok']);
        $this->assertSame('CONTENT_TOO_LARGE', $r['errorCode']);
    }

    #[Test]
    public function raw_count_rejects_excess_messages(): void
    {
        config(['tutor.chat_stream.max_messages' => 1]);
        $r = TutorChatRequestLimits::validateRawMessageCount([
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'user', 'content' => 'b'],
        ]);
        $this->assertFalse($r['ok']);
        $this->assertSame('MESSAGE_LIMIT', $r['errorCode']);
    }
}
