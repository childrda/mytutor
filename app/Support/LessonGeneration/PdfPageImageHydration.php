<?php

namespace App\Support\LessonGeneration;

/**
 * Replaces {@code pdf_page:N} image src placeholders with rasterized PDF page data URLs.
 */
final class PdfPageImageHydration
{
    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<string>  $dataUrlsZeroIndexed
     * @return list<array<string, mixed>>
     */
    public static function hydrateScenes(array $scenes, array $dataUrlsZeroIndexed): array
    {
        foreach ($scenes as &$scene) {
            if (($scene['type'] ?? '') !== 'slide') {
                continue;
            }
            $content = $scene['content'] ?? null;
            if (! is_array($content)) {
                continue;
            }
            $canvas = $content['canvas'] ?? null;
            if (! is_array($canvas)) {
                continue;
            }
            $elements = $canvas['elements'] ?? null;
            if (! is_array($elements)) {
                continue;
            }
            foreach ($elements as &$el) {
                if (! is_array($el)) {
                    continue;
                }
                if (($el['type'] ?? '') !== 'image') {
                    continue;
                }
                $src = isset($el['src']) && is_string($el['src']) ? trim($el['src']) : '';
                if (preg_match('/^pdf_page:(\d+)$/', $src, $m) === 1) {
                    $idx = (int) $m[1] - 1;
                    if ($idx >= 0 && $idx < count($dataUrlsZeroIndexed) && is_string($dataUrlsZeroIndexed[$idx])) {
                        $el['src'] = $dataUrlsZeroIndexed[$idx];
                    }
                }
            }
            unset($el);
            $canvas['elements'] = $elements;
            $content['canvas'] = $canvas;
            $scene['content'] = $content;
        }
        unset($scene);

        return $scenes;
    }
}
