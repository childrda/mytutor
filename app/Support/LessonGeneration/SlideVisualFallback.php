<?php

namespace App\Support\LessonGeneration;

use Illuminate\Support\Facades\Http;
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
        $match = self::matchDiagram($haystack, $requirement);
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
     * @var array<string, array{url: string, alt: string}|null>
     */
    private static array $wikimediaCache = [];

    /**
     * @return array{url: string, alt: string}|null
     */
    private static function matchDiagram(string $haystack, string $requirement): ?array
    {
        foreach (self::DIAGRAMS as $row) {
            foreach ($row['needles'] as $needle) {
                if (str_contains($haystack, $needle)) {
                    return ['url' => $row['url'], 'alt' => $row['alt']];
                }
            }
        }

        if (! self::wikimediaFallbackEnabled()) {
            return null;
        }

        $topic = trim($requirement);
        if ($topic === '') {
            $clean = preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $haystack));
            $clean = trim(mb_strtolower($clean));
            $words = preg_split('/\s+/u', $clean, 10);
            $words = array_values(array_filter($words, static fn (string $w): bool => mb_strlen($w) > 1));
            $topic = implode(' ', array_slice($words, 0, 4));
        }
        $topic = trim(mb_substr($topic, 0, 80));
        if (mb_strlen($topic) < 3) {
            return null;
        }

        return self::fetchWikimediaImage($topic);
    }

    private static function wikimediaFallbackEnabled(): bool
    {
        if (function_exists('app') && app()->bound('config')) {
            return (bool) config('tutor.lesson_generation.slide_visual_fallback_wikimedia', true);
        }

        return true;
    }

    /**
     * @return array{url: string, alt: string}|null
     */
    private static function fetchWikimediaImage(string $topic): ?array
    {
        $key = mb_strtolower($topic);
        if (array_key_exists($key, self::$wikimediaCache)) {
            return self::$wikimediaCache[$key];
        }
        $resolved = self::fetchWikimediaImageUncached($topic);
        self::$wikimediaCache[$key] = $resolved;

        return $resolved;
    }

    /**
     * @return array{url: string, alt: string}|null
     */
    private static function fetchWikimediaImageUncached(string $topic): ?array
    {
        $query = rawurlencode($topic);
        $url = 'https://en.wikipedia.org/w/api.php?action=query&generator=search'
            ."&gsrsearch={$query}&gsrnamespace=6&gsrlimit=8&prop=imageinfo&iiprop=url|mime&iiurlwidth=520&format=json&origin=*";

        try {
            $response = Http::timeout(3)
                ->connectTimeout(2)
                ->withHeaders([
                    'User-Agent' => trim((string) config('app.name', 'MyTutor')).'/1.0 (slide visual fallback; +https://www.mediawiki.org/wiki/API:Main_page)',
                ])
                ->get($url);
            if (! $response->successful()) {
                return null;
            }
            $data = $response->json();
            $pages = $data['query']['pages'] ?? null;
            if (! is_array($pages)) {
                return null;
            }
            foreach ($pages as $page) {
                if (! is_array($page)) {
                    continue;
                }
                $info = $page['imageinfo'][0] ?? null;
                if (! is_array($info)) {
                    continue;
                }
                $mime = isset($info['mime']) && is_string($info['mime']) ? $info['mime'] : '';
                if ($mime !== '' && ! str_starts_with($mime, 'image/')) {
                    continue;
                }
                $thumbUrl = isset($info['thumburl']) && is_string($info['thumburl']) ? $info['thumburl'] : '';
                $fullUrl = isset($info['url']) && is_string($info['url']) ? $info['url'] : '';
                $pick = str_starts_with($thumbUrl, 'https://') ? $thumbUrl : (str_starts_with($fullUrl, 'https://') ? $fullUrl : '');
                if ($pick === '') {
                    continue;
                }
                $title = isset($page['title']) && is_string($page['title']) ? $page['title'] : 'Educational image';
                $alt = preg_replace('/^File:/i', '', $title);

                return ['url' => $pick, 'alt' => $alt];
            }
        } catch (\Throwable) {
            return null;
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
