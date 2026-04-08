<?php

namespace App\Http\Resources;

use App\Models\TutorScene;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape aligned with the reference classroom “scene” model (camelCase, ms timestamps).
 *
 * @mixin TutorScene
 */
class TutorSceneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $content = $this->content ?? [];
        $content = is_array($content) ? self::normalizeSlideContentStorageUrls($content) : [];

        return [
            'id' => $this->id,
            'stageId' => $this->tutor_lesson_id,
            'type' => $this->type,
            'title' => $this->title,
            'order' => (int) $this->scene_order,
            'content' => $content,
            'actions' => $this->actions,
            'whiteboards' => $this->whiteboard,
            'multiAgent' => $this->multi_agent,
            'createdAt' => $this->created_at?->getTimestampMs(),
            'updatedAt' => $this->updated_at?->getTimestampMs(),
        ];
    }

    /**
     * Stored scenes often contain absolute URLs from APP_URL (e.g. http://localhost:8080/storage/...).
     * If the user opens the app on another host/port/scheme, those URLs break. Root-relative /storage/...
     * resolves against the current page origin.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function normalizeSlideContentStorageUrls(array $content): array
    {
        if (($content['type'] ?? '') !== 'slide') {
            return $content;
        }
        $canvas = $content['canvas'] ?? null;
        if (! is_array($canvas) || ! isset($canvas['elements']) || ! is_array($canvas['elements'])) {
            return $content;
        }
        foreach ($canvas['elements'] as $i => $el) {
            if (! is_array($el) || ($el['type'] ?? '') !== 'image') {
                continue;
            }
            $src = $el['src'] ?? null;
            if (! is_string($src) || $src === '') {
                continue;
            }
            $canvas['elements'][$i]['src'] = self::absoluteStorageUrlToRelativePath($src);
        }
        $content['canvas'] = $canvas;

        return $content;
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @return list<array<string, mixed>>
     */
    public static function normalizeScenesListStorageUrls(array $scenes): array
    {
        $out = [];
        foreach ($scenes as $scene) {
            if (! is_array($scene)) {
                $out[] = $scene;

                continue;
            }
            $content = $scene['content'] ?? null;
            if (is_array($content)) {
                $scene['content'] = self::normalizeSlideContentStorageUrls($content);
            }
            $out[] = $scene;
        }

        return $out;
    }

    private static function absoluteStorageUrlToRelativePath(string $src): string
    {
        $src = trim($src);
        if ($src === '' || str_starts_with($src, '/')) {
            return $src;
        }
        $parts = parse_url($src);
        if (! is_array($parts) || ! isset($parts['path']) || ! is_string($parts['path'])) {
            return $src;
        }
        $path = $parts['path'];
        if (! str_starts_with($path, '/storage/')) {
            return $src;
        }
        $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== ''
            ? '?'.$parts['query']
            : '';

        return $path.$query;
    }
}
