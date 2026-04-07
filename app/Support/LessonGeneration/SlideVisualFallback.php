<?php

namespace App\Support\LessonGeneration;

use Illuminate\Support\Str;

/**
 * When the model returns text-only slides (no image, no cards), inject a vetted HTTPS diagram
 * if the topic matches a small keyword list. Keeps layout readable in a two-column pattern.
 */
final class SlideVisualFallback
{
    /**
     * @var list<array{needles: list<string>, url: string, alt: string}>
     */
    private const DIAGRAMS = [
        [
            'needles' => ['water cycle', 'hydrologic cycle', 'hydrological cycle'],
            'url' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/1/19/Watercycle.jpg/640px-Watercycle.jpg',
            'alt' => 'Diagram of the water cycle',
        ],
        [
            'needles' => ['photosynthesis'],
            'url' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/6/6b/Photosynthesis_en.svg/520px-Photosynthesis_en.svg.png',
            'alt' => 'Photosynthesis diagram',
        ],
        [
            'needles' => ['food chain', 'food web', 'trophic'],
            'url' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/7/72/Food_web_diagram.svg/520px-Food_web_diagram.svg.png',
            'alt' => 'Food web diagram',
        ],
        [
            'needles' => ['cell', 'mitochondria', 'nucleus', 'organelle'],
            'url' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/48/Animal_Cell.svg/520px-Animal_Cell.svg.png',
            'alt' => 'Animal cell diagram',
        ],
        [
            'needles' => ['solar system', 'planet orbit'],
            'url' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/c/cb/Planets2013.svg/520px-Planets2013.svg.png',
            'alt' => 'Solar system diagram',
        ],
        [
            'needles' => ['rock cycle'],
            'url' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/6/6d/Rock_cycle_diagram.png/520px-Rock_cycle_diagram.png',
            'alt' => 'Rock cycle diagram',
        ],
        [
            'needles' => ['carbon cycle'],
            'url' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/7/7a/Carbon_cycle.jpg/520px-Carbon_cycle.jpg',
            'alt' => 'Carbon cycle diagram',
        ],
        [
            'needles' => ['nitrogen cycle'],
            'url' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/1/1f/Nitrogen_Cycle.svg/520px-Nitrogen_Cycle.svg.png',
            'alt' => 'Nitrogen cycle diagram',
        ],
    ];

    /**
     * @param  array<string, mixed>  $scene
     * @return array<string, mixed>
     */
    public static function applyToScene(array $scene, string $requirement): array
    {
        if (($scene['type'] ?? 'slide') !== 'slide') {
            return $scene;
        }
        $content = $scene['content'] ?? null;
        if (! is_array($content) || ($content['type'] ?? '') !== 'slide') {
            return $scene;
        }
        $canvas = $content['canvas'] ?? null;
        if (! is_array($canvas)) {
            return $scene;
        }
        $els = $canvas['elements'] ?? null;
        if (! is_array($els)) {
            return $scene;
        }
        if (self::canvasHasRenderableImage($els)) {
            return $scene;
        }
        foreach ($els as $el) {
            if (is_array($el) && ($el['type'] ?? '') === 'card') {
                return $scene;
            }
        }

        $title = trim((string) ($scene['title'] ?? ''));
        $canvasTitle = trim((string) ($canvas['title'] ?? ''));
        $textBlob = self::concatTextFromElements($els);
        $haystack = mb_strtolower($requirement.' '.$title.' '.$canvasTitle.' '.$textBlob);
        $match = self::matchDiagram($haystack);
        if ($match === null) {
            return $scene;
        }

        $image = [
            'type' => 'image',
            'id' => (string) Str::ulid(),
            'x' => 40,
            'y' => 165,
            'width' => 450,
            'height' => 340,
            'src' => $match['url'],
            'alt' => $match['alt'],
        ];

        $newEls = [$image];
        foreach ($els as $el) {
            if (! is_array($el)) {
                continue;
            }
            if (($el['type'] ?? '') === 'text') {
                $el = self::reflowTextElementRight($el);
            }
            $newEls[] = $el;
        }

        $canvas['elements'] = $newEls;
        $content['canvas'] = $canvas;
        $scene['content'] = $content;

        return $scene;
    }

    /**
     * @param  list<array<string, mixed>>  $els
     */
    private static function canvasHasRenderableImage(array $els): bool
    {
        foreach ($els as $el) {
            if (! is_array($el) || ($el['type'] ?? '') !== 'image') {
                continue;
            }
            $src = trim((string) ($el['src'] ?? ''));
            if ($src === '') {
                continue;
            }
            if (str_starts_with($src, 'https://') || str_starts_with($src, 'http://')) {
                return true;
            }
            if (str_starts_with($src, 'data:image')) {
                return true;
            }
            if (str_starts_with($src, 'pdf_page:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $els
     */
    private static function concatTextFromElements(array $els): string
    {
        $parts = [];
        foreach ($els as $el) {
            if (! is_array($el) || ($el['type'] ?? '') !== 'text') {
                continue;
            }
            $t = isset($el['text']) && is_string($el['text']) ? trim($el['text']) : '';
            if ($t !== '') {
                $parts[] = $t;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{url: string, alt: string}|null
     */
    private static function matchDiagram(string $haystack): ?array
    {
        foreach (self::DIAGRAMS as $row) {
            foreach ($row['needles'] as $needle) {
                if (str_contains($haystack, $needle)) {
                    return ['url' => $row['url'], 'alt' => $row['alt']];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $el
     * @return array<string, mixed>
     */
    private static function reflowTextElementRight(array $el): array
    {
        $w = isset($el['width']) && is_numeric($el['width']) ? (int) $el['width'] : 0;
        $x = isset($el['x']) && is_numeric($el['x']) ? (int) $el['x'] : 48;
        if ($w >= 550 || ($w >= 400 && $x <= 80)) {
            $el['x'] = 510;
            $el['width'] = 460;
            $y = isset($el['y']) && is_numeric($el['y']) ? (int) $el['y'] : 120;
            $el['y'] = $y < 160 ? 165 : $y;
            $h = isset($el['height']) && is_numeric($el['height']) ? (int) $el['height'] : 320;
            $el['height'] = min(360, max(240, $h));
            if (isset($el['fontSize']) && is_numeric($el['fontSize']) && (int) $el['fontSize'] > 20) {
                $el['fontSize'] = 20;
            }
        }

        return $el;
    }
}
