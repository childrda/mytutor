<?php

namespace Tests\Unit;

use App\Support\LessonGeneration\StreamingLessonOutlineParser;
use PHPUnit\Framework\TestCase;

class StreamingLessonOutlineParserTest extends TestCase
{
    public function test_extracts_two_complete_objects_from_partial_buffer(): void
    {
        $buf = '{"outline":[ {"id":"a","type":"slide","title":"Intro","order":0,"objective":"","notes":""}, {"id":"b","type":"quiz","title":"Q","order":1,"objective":"","notes":""}';
        $items = StreamingLessonOutlineParser::extractOutlineObjects($buf);
        $this->assertCount(2, $items);
        $this->assertSame('Intro', $items[0]['title']);
        $this->assertSame('Q', $items[1]['title']);
    }

    public function test_incomplete_second_object_returns_only_complete(): void
    {
        $buf = '{"outline":[{"id":"a","title":"T","type":"slide","order":0,"objective":"x","notes":""},{"id":"b","title":';
        $items = StreamingLessonOutlineParser::extractOutlineObjects($buf);
        $this->assertCount(1, $items);
        $this->assertSame('T', $items[0]['title']);
    }

    public function test_strip_markdown_fences(): void
    {
        $raw = "```json\n{\"outline\":[]}\n```";
        $this->assertSame('{"outline":[]}', StreamingLessonOutlineParser::stripMarkdownFences($raw));
    }

    public function test_braces_inside_string_do_not_break_balancing(): void
    {
        $buf = '{"outline":[{"id":"a","title":"Use {braces}","type":"slide","order":0,"objective":"","notes":""}]}';
        $items = StreamingLessonOutlineParser::extractOutlineObjects($buf);
        $this->assertCount(1, $items);
        $this->assertSame('Use {braces}', $items[0]['title']);
    }
}
