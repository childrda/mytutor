<?php

namespace App\Support\Tutor;

/**
 * Phase 7.4: validates TutorScene.actions against lesson personas and slide element ids.
 */
final class TeachingActionsValidator
{
    public const ACTIONS_SCHEMA_VERSION = 1;

    /** @var list<string> */
    private const CORE_TYPES = ['speech', 'spotlight', 'interact'];

    /** @var list<string> */
    private const LEGACY_TYPES = ['cue', 'narration', 'highlight', 'media'];

    /**
     * @param  list<array<string, mixed>>|null  $actions
     * @param  array<string, mixed>|null  $sceneContent
     * @param  array<string, mixed>|null  $lessonMeta
     * @return list<string> Human-readable errors (empty = valid)
     */
    public static function messagesFor(?array $actions, ?array $sceneContent, ?array $lessonMeta, ?string $sceneType): array
    {
        if ($actions === null || $actions === []) {
            return [];
        }

        $errors = [];
        $personaSet = self::personaIdSet($lessonMeta);
        $elementIds = self::canvasElementIds($sceneContent);
        $isSlide = ($sceneType ?? '') === 'slide';

        foreach ($actions as $i => $action) {
            if (! is_array($action)) {
                $errors[] = "Action #{$i} must be an object.";

                continue;
            }
            $prefix = "Action #{$i}: ";
            $type = strtolower(trim((string) ($action['type'] ?? '')));
            if ($type === '') {
                $errors[] = $prefix.'Missing type.';

                continue;
            }

            if (in_array($type, self::LEGACY_TYPES, true)) {
                continue;
            }

            if (! in_array($type, self::CORE_TYPES, true)) {
                $errors[] = $prefix."Unknown type \"{$type}\".";

                continue;
            }

            if ($type === 'speech') {
                $text = isset($action['text']) && is_string($action['text']) ? trim($action['text']) : '';
                $label = isset($action['label']) && is_string($action['label']) ? trim($action['label']) : '';
                if ($text === '' && $label === '') {
                    $errors[] = $prefix.'Speech requires text or label.';
                }
                $pid = $action['personaId'] ?? null;
                if (is_string($pid) && $pid !== '' && $personaSet !== []) {
                    if (! isset($personaSet[$pid])) {
                        $errors[] = $prefix.'personaId is not in lesson classroomRoles.';
                    }
                }
            }

            if ($type === 'spotlight') {
                $target = $action['target'] ?? null;
                if (! is_array($target)) {
                    $errors[] = $prefix.'Spotlight requires target object.';

                    continue;
                }
                $kind = strtolower(trim((string) ($target['kind'] ?? '')));
                if (! in_array($kind, ['element', 'region'], true)) {
                    $errors[] = $prefix.'Spotlight target.kind must be "element" or "region".';

                    continue;
                }
                if ($kind === 'element') {
                    if (! $isSlide) {
                        $errors[] = $prefix.'Spotlight element targets are only valid on slide scenes.';

                        continue;
                    }
                    $eid = isset($target['elementId']) && is_string($target['elementId']) ? trim($target['elementId']) : '';
                    if ($eid === '') {
                        $errors[] = $prefix.'Spotlight element target requires elementId.';
                    } elseif ($elementIds !== [] && ! isset($elementIds[$eid])) {
                        $errors[] = $prefix.'Spotlight elementId does not exist on this slide canvas.';
                    }
                }
                if ($kind === 'region') {
                    $rect = $target['rect'] ?? null;
                    if ($rect !== null && ! is_array($rect)) {
                        $errors[] = $prefix.'Spotlight region rect must be an object.';
                    }
                }
            }

            if ($type === 'interact') {
                $mode = strtolower(trim((string) ($action['mode'] ?? '')));
                if (! in_array($mode, ['pause', 'quiz_gate'], true)) {
                    $errors[] = $prefix.'Interact mode must be "pause" or "quiz_gate".';
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, true>
     */
    private static function personaIdSet(?array $meta): array
    {
        if (! is_array($meta)) {
            return [];
        }
        $cr = $meta['classroomRoles'] ?? null;
        if (! is_array($cr)) {
            return [];
        }
        $out = [];
        foreach ($cr['personas'] ?? [] as $p) {
            if (is_array($p) && isset($p['id']) && is_string($p['id']) && $p['id'] !== '') {
                $out[$p['id']] = true;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $content
     * @return array<string, true>
     */
    private static function canvasElementIds(?array $content): array
    {
        if (! is_array($content)) {
            return [];
        }
        $canvas = $content['canvas'] ?? null;
        if (! is_array($canvas)) {
            return [];
        }
        $elements = $canvas['elements'] ?? null;
        if (! is_array($elements)) {
            return [];
        }
        $out = [];
        foreach ($elements as $el) {
            if (! is_array($el)) {
                continue;
            }
            $id = $el['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $out[$id] = true;
            }
        }

        return $out;
    }
}
