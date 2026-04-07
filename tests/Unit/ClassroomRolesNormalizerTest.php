<?php

namespace Tests\Unit;

use App\Support\LessonGeneration\ClassroomRolesNormalizer;
use PHPUnit\Framework\TestCase;

class ClassroomRolesNormalizerTest extends TestCase
{
    public function test_fallback_produces_four_personas(): void
    {
        $out = ClassroomRolesNormalizer::fallback('Learn fractions', 'Fractions 101');
        $this->assertSame(1, $out['version']);
        $this->assertCount(4, $out['personas']);
        $this->assertSame('teacher', $out['personas'][0]['role']);
        $this->assertArrayHasKey('id', $out['personas'][0]);
        $this->assertArrayHasKey('accentColor', $out['personas'][0]);
    }

    public function test_normalize_empty_raw_uses_fallback(): void
    {
        $out = ClassroomRolesNormalizer::normalize(null, 'Topic', 'Lesson');
        $this->assertCount(4, $out['personas']);
    }

    public function test_normalize_keeps_valid_personas(): void
    {
        $raw = [
            'version' => 1,
            'personas' => [
                [
                    'id' => 'p1',
                    'role' => 'teacher',
                    'name' => 'Dr. Chen',
                    'bio' => 'Hosts the session.',
                    'accentColor' => '#FF00AB',
                ],
            ],
        ];
        $out = ClassroomRolesNormalizer::normalize($raw, 'x', 'y');
        $this->assertCount(1, $out['personas']);
        $this->assertSame('Dr. Chen', $out['personas'][0]['name']);
        $this->assertSame('#FF00AB', $out['personas'][0]['accentColor']);
    }

    public function test_normalize_drops_invalid_accent_color(): void
    {
        $raw = [
            'personas' => [
                ['role' => 'student', 'name' => 'A', 'bio' => 'Hi', 'accentColor' => 'not-a-color'],
            ],
        ];
        $out = ClassroomRolesNormalizer::normalize($raw, 'x', 'y');
        $this->assertArrayNotHasKey('accentColor', $out['personas'][0]);
    }
}
