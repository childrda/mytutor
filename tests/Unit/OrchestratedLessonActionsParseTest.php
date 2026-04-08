<?php

namespace Tests\Unit;

use App\Services\LessonGeneration\OrchestratedLessonGenerationService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class OrchestratedLessonActionsParseTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private static function parseActions(string $raw, string $teacherId, array $scene): array
    {
        $m = (new ReflectionClass(OrchestratedLessonGenerationService::class))->getMethod('parseLlmActionsJson');
        $m->setAccessible(true);
        $svc = app(OrchestratedLessonGenerationService::class);

        return $m->invoke($svc, $raw, $teacherId, $scene);
    }

    #[Test]
    public function spotlight_element_id_matches_case_insensitively(): void
    {
        $scene = [
            'type' => 'slide',
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'elements' => [
                        ['type' => 'image', 'id' => 'water-cycle-hero', 'src' => 'https://example.com/x.png'],
                        ['type' => 'card', 'id' => 'key-ideas', 'title' => 'Key ideas', 'bullets' => ['a']],
                    ],
                ],
            ],
        ];
        $raw = '{"actions":[{"type":"spotlight","label":"Diagram","target":{"elementId":"Water-Cycle-Hero"},"durationMs":3000}]}';
        $out = self::parseActions($raw, 'teacher-1', $scene);
        $this->assertCount(1, $out);
        $this->assertSame('spotlight', $out[0]['type']);
        $this->assertSame('water-cycle-hero', $out[0]['target']['elementId']);
    }
}
