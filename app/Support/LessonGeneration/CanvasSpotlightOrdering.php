<?php

namespace App\Support\LessonGeneration;

/**
 * Determines canvas element order for teaching walkthroughs: diagram first, then cards (top-to-bottom, left-to-right), then text.
 */
final class CanvasSpotlightOrdering
{
    /**
     * @param  array<string, mixed>  $canvas
     * @return list<string>
     */
    public static function spotlightElementIds(array $canvas): array
    {
        $elements = isset($canvas['elements']) && is_array($canvas['elements']) ? $canvas['elements'] : [];
        $buckets = ['image' => [], 'card' => [], 'text' => []];
        foreach ($elements as $el) {
            if (! is_array($el)) {
                continue;
            }
            $t = strtolower(trim((string) ($el['type'] ?? '')));
            if (! isset($buckets[$t])) {
                continue;
            }
            $id = isset($el['id']) && is_string($el['id']) && $el['id'] !== '' ? $el['id'] : null;
            if ($id === null) {
                continue;
            }
            $x = isset($el['x']) && is_numeric($el['x']) ? (float) $el['x'] : 0.0;
            $y = isset($el['y']) && is_numeric($el['y']) ? (float) $el['y'] : 0.0;
            $buckets[$t][] = ['id' => $id, 'x' => $x, 'y' => $y];
        }
        $sort = static function (array $a, array $b): int {
            if ($a['y'] !== $b['y']) {
                return $a['y'] <=> $b['y'];
            }

            return $a['x'] <=> $b['x'];
        };
        foreach (array_keys($buckets) as $k) {
            usort($buckets[$k], $sort);
        }
        $out = [];
        foreach (['image', 'card', 'text'] as $k) {
            foreach ($buckets[$k] as $row) {
                $out[] = $row['id'];
            }
        }

        return $out;
    }
}
