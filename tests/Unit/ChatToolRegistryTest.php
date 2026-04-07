<?php

namespace Tests\Unit;

use App\Services\Ai\ChatToolRegistry;
use App\Services\Ai\InvalidToolArgumentsException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatToolRegistryTest extends TestCase
{
    #[Test]
    public function whiteboard_append_is_registered_and_executes_noop(): void
    {
        $this->assertTrue(ChatToolRegistry::isRegistered('whiteboard_append'));
        $args = ChatToolRegistry::parseAndValidateArguments('whiteboard_append', '{}');
        $out = ChatToolRegistry::execute('whiteboard_append', $args);
        $this->assertTrue($out['ok'] ?? false);
    }

    #[Test]
    public function parse_rejects_unknown_extra_property_when_additional_properties_false(): void
    {
        $this->expectException(InvalidToolArgumentsException::class);
        ChatToolRegistry::parseAndValidateArguments('whiteboard_append', '{"evil":true}');
    }

    #[Test]
    public function parse_accepts_optional_label_string(): void
    {
        $args = ChatToolRegistry::parseAndValidateArguments('whiteboard_append', '{"label":"Hi"}');
        $this->assertSame('Hi', $args['label']);
    }

    #[Test]
    public function open_ai_definitions_include_demo_tool(): void
    {
        $defs = ChatToolRegistry::openAiToolDefinitions();
        $names = array_map(fn (array $d) => $d['function']['name'] ?? '', $defs);
        $this->assertContains('whiteboard_append', $names);
    }
}
