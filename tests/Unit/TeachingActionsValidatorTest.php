<?php

namespace Tests\Unit;

use App\Support\Tutor\TeachingActionsValidator;
use PHPUnit\Framework\TestCase;

class TeachingActionsValidatorTest extends TestCase
{
    public function test_empty_actions_ok(): void
    {
        $this->assertSame([], TeachingActionsValidator::messagesFor([], null, null, 'slide'));
    }

    public function test_spotlight_element_requires_valid_id_on_slide(): void
    {
        $content = [
            'canvas' => [
                'elements' => [
                    ['type' => 'text', 'id' => 'el-1', 'text' => 'Hi'],
                ],
            ],
        ];
        $meta = ['classroomRoles' => ['personas' => [['id' => 'p1', 'role' => 'teacher', 'name' => 'T', 'bio' => '']]]];

        $bad = [
            ['type' => 'spotlight', 'target' => ['kind' => 'element', 'elementId' => 'missing']],
        ];
        $msgs = TeachingActionsValidator::messagesFor($bad, $content, $meta, 'slide');
        $this->assertNotEmpty($msgs);

        $good = [
            ['type' => 'spotlight', 'target' => ['kind' => 'element', 'elementId' => 'el-1']],
        ];
        $this->assertSame([], TeachingActionsValidator::messagesFor($good, $content, $meta, 'slide'));
    }

    public function test_persona_must_exist_when_roster_defined(): void
    {
        $meta = ['classroomRoles' => ['personas' => [['id' => 'p1', 'role' => 'teacher', 'name' => 'T', 'bio' => '']]]];
        $bad = [['type' => 'speech', 'text' => 'Hi', 'personaId' => 'nope']];
        $this->assertNotEmpty(TeachingActionsValidator::messagesFor($bad, null, $meta, 'slide'));

        $ok = [['type' => 'speech', 'text' => 'Hi', 'personaId' => 'p1']];
        $this->assertSame([], TeachingActionsValidator::messagesFor($ok, null, $meta, 'slide'));
    }
}
