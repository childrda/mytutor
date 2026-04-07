<?php

namespace Tests\Unit;

use App\Support\Chat\TutorChatDirectorState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TutorChatDirectorStateTest extends TestCase
{
    #[Test]
    public function merge_for_done_appends_agent_responses_and_preserves_ledger(): void
    {
        $baseline = [
            'turnCount' => 2,
            'agentResponses' => [['agentId' => 'tutor', 'content' => 'prior']],
            'whiteboardLedger' => [['op' => 'stroke']],
        ];
        $thisTurn = [
            ['agentId' => 'socratic', 'agentName' => 'S', 'content' => 'next'],
        ];

        $merged = TutorChatDirectorState::mergeForDone($baseline, $thisTurn, []);

        $this->assertSame(2, $merged['turnCount']);
        $this->assertCount(2, $merged['agentResponses']);
        $this->assertSame('next', $merged['agentResponses'][1]['content']);
        $this->assertSame([['op' => 'stroke']], $merged['whiteboardLedger']);
    }

    #[Test]
    public function sanitize_incoming_clamps_turn_count_and_truncates_lists(): void
    {
        $raw = [
            'turnCount' => 2_000_000,
            'agentResponses' => array_fill(0, 50, ['x' => 1]),
            'whiteboardLedger' => array_fill(0, 60, ['y' => 1]),
        ];
        $out = TutorChatDirectorState::sanitizeIncoming($raw);
        $this->assertSame(1_000_000, $out['turnCount']);
        $this->assertCount(40, $out['agentResponses']);
        $this->assertCount(50, $out['whiteboardLedger']);
    }
}
