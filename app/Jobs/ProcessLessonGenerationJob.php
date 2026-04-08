<?php

namespace App\Jobs;

use App\Models\LessonGenerationJob;
use App\Services\LessonGeneration\OrchestratedLessonGenerationService;
use App\Support\LessonGeneration\LessonGenerationPhases;
use App\Support\LessonGeneration\PipelineStepException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Dispatches the Phase 7.3 orchestrated pipeline (sequential LLM calls).
 */
class ProcessLessonGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public string $jobUlid,
    ) {}

    public function handle(OrchestratedLessonGenerationService $pipeline): void
    {
        $record = LessonGenerationJob::query()->find($this->jobUlid);
        if (! $record) {
            return;
        }

        try {
            $pipeline->run($record);
        } catch (PipelineStepException $e) {
            $this->markPipelineFailed($record, $e->getMessage(), $e->step);
        } catch (Throwable $e) {
            $this->markPipelineFailed($record, $e->getMessage(), 'unknown');
        }
    }

    private function markPipelineFailed(LessonGenerationJob $record, string $message, string $step): void
    {
        $record->refresh();
        $current = is_array($record->result) ? $record->result : [];
        $record->update([
            'status' => 'failed',
            'phase' => LessonGenerationPhases::FAILED,
            'error' => $message,
            'result' => array_merge($current, [
                'pipelineFailed' => true,
                'pipelineFailedStep' => $step,
            ]),
        ]);
    }

    /**
     * Ensures slide scenes have canvas.elements with visible text (models often omit type:"text" or leave elements empty).
     *
     * @param  array<string, mixed>  $scene
     * @return array<string, mixed>
     */
    /**
     * Preserve LLM/chosen element ids when JSON decoding leaves them as strings; coerce scalars so we do not replace with ULIDs unnecessarily.
     */
    private static function normalizeCanvasElementId(mixed $raw): string
    {
        if (is_string($raw)) {
            $s = trim($raw);
            if ($s !== '') {
                return mb_substr($s, 0, 128);
            }
        }
        if (is_int($raw) || is_float($raw)) {
            $s = trim((string) $raw);
            if ($s !== '') {
                return mb_substr($s, 0, 128);
            }
        }

        return (string) Str::ulid();
    }

    public static function enrichSlideScene(array $scene, string $stageDescription, string $requirement): array
    {
        $type = $scene['type'] ?? 'slide';
        if ($type !== 'slide') {
            return $scene;
        }

        $content = $scene['content'] ?? null;
        if (is_string($content)) {
            $parsed = json_decode($content, true);
            $content = is_array($parsed) ? $parsed : [];
        } elseif (! is_array($content)) {
            $content = [];
        }
        $content['type'] = 'slide';

        $canvas = isset($content['canvas']) && is_array($content['canvas']) ? $content['canvas'] : [];
        $canvas['width'] = isset($canvas['width']) && is_numeric($canvas['width']) ? (int) $canvas['width'] : 1000;
        $canvas['height'] = isset($canvas['height']) && is_numeric($canvas['height']) ? (float) $canvas['height'] : 562.5;

        $title = is_string($scene['title'] ?? null) && $scene['title'] !== '' ? $scene['title'] : 'Slide';
        if (! isset($canvas['title']) || ! is_string($canvas['title']) || trim($canvas['title']) === '') {
            $canvas['title'] = $title;
        }

        $rawElements = isset($canvas['elements']) && is_array($canvas['elements']) ? $canvas['elements'] : [];
        $elements = [];
        foreach ($rawElements as $el) {
            if (! is_array($el)) {
                continue;
            }
            $typeRaw = strtolower(trim((string) ($el['type'] ?? '')));
            if ($typeRaw === 'card') {
                $card = self::normalizeCardElement($el);
                if ($card !== null) {
                    $elements[] = $card;
                }

                continue;
            }
            if ($typeRaw === 'image') {
                $src = isset($el['src']) && is_string($el['src']) ? trim($el['src']) : '';
                if ($src === '') {
                    // Empty src was dropped entirely, so later stages never ran image API / fallbacks.
                    $src = 'ai_generate:pending';
                }
                $elements[] = [
                    'type' => 'image',
                    'id' => self::normalizeCanvasElementId($el['id'] ?? null),
                    'x' => isset($el['x']) && is_numeric($el['x']) ? (int) $el['x'] : 40,
                    'y' => isset($el['y']) && is_numeric($el['y']) ? (int) $el['y'] : 175,
                    'width' => isset($el['width']) && is_numeric($el['width']) ? max(80, (int) $el['width']) : 440,
                    'height' => isset($el['height']) && is_numeric($el['height']) ? max(60, (int) $el['height']) : 320,
                    'src' => $src,
                    'alt' => isset($el['alt']) && is_string($el['alt']) ? trim($el['alt']) : '',
                ];

                continue;
            }
            $text = '';
            if (isset($el['text']) && is_string($el['text'])) {
                $text = trim($el['text']);
            } elseif (isset($el['content']) && is_string($el['content'])) {
                $text = trim($el['content']);
            }
            if ($text === '') {
                continue;
            }
            $elements[] = [
                'type' => 'text',
                'id' => self::normalizeCanvasElementId($el['id'] ?? null),
                'x' => isset($el['x']) && is_numeric($el['x']) ? (int) $el['x'] : 48,
                'y' => isset($el['y']) && is_numeric($el['y']) ? (int) $el['y'] : 120,
                'width' => isset($el['width']) && is_numeric($el['width']) ? (int) $el['width'] : 420,
                'height' => isset($el['height']) && is_numeric($el['height']) ? (int) $el['height'] : 120,
                'fontSize' => isset($el['fontSize']) && is_numeric($el['fontSize']) ? (int) $el['fontSize'] : 22,
                'text' => $text,
            ];
        }

        if ($elements === []) {
            $body = '';
            if (isset($content['body']) && is_string($content['body'])) {
                $body = trim($content['body']);
            }
            if ($body === '' && isset($content['markdown']) && is_string($content['markdown'])) {
                $body = trim($content['markdown']);
            }
            if ($body === '' && isset($content['bullets']) && is_array($content['bullets'])) {
                $lines = [];
                foreach ($content['bullets'] as $b) {
                    if (is_string($b) && trim($b) !== '') {
                        $lines[] = '• '.trim($b);
                    }
                }
                $body = implode("\n", $lines);
            }
            if ($body === '' && isset($content['keyPoints']) && is_array($content['keyPoints'])) {
                $lines = [];
                foreach ($content['keyPoints'] as $b) {
                    if (is_string($b) && trim($b) !== '') {
                        $lines[] = '• '.trim($b);
                    }
                }
                $body = implode("\n", $lines);
            }
            if ($body === '' && isset($scene['notes']) && is_string($scene['notes'])) {
                $body = trim($scene['notes']);
            }
            if ($body === '' && isset($scene['script']) && is_string($scene['script'])) {
                $body = trim($scene['script']);
            }
            if ($body !== '') {
                $elements[] = [
                    'type' => 'text',
                    'id' => (string) Str::ulid(),
                    'x' => 48,
                    'y' => 120,
                    'width' => 900,
                    'height' => 420,
                    'fontSize' => 20,
                    'text' => $body,
                ];
            }
        }

        if ($elements === []) {
            $snippet = $stageDescription !== '' ? mb_substr($stageDescription, 0, 700) : mb_substr(trim($requirement), 0, 700);
            $headline = is_string($canvas['title'] ?? null) ? $canvas['title'] : $title;
            $fallback = $snippet !== ''
                ? $headline."\n\n".$snippet
                : $headline."\n\n(Regenerate this lesson for richer slide text — the model returned no drawable text elements.)";
            $elements[] = [
                'type' => 'text',
                'id' => (string) Str::ulid(),
                'x' => 48,
                'y' => 120,
                'width' => 900,
                'height' => 420,
                'fontSize' => 20,
                'text' => $fallback,
            ];
        }

        unset($content['body'], $content['bullets'], $content['keyPoints'], $content['markdown']);
        $canvas['elements'] = $elements;
        $content['canvas'] = $canvas;
        $scene['content'] = $content;

        return $scene;
    }

    /**
     * @param  array<string, mixed>  $el
     * @return array<string, mixed>|null
     */
    private static function normalizeCardElement(array $el): ?array
    {
        $title = isset($el['title']) && is_string($el['title']) ? trim($el['title']) : '';
        $body = isset($el['body']) && is_string($el['body']) ? trim($el['body']) : '';
        $caption = isset($el['caption']) && is_string($el['caption']) ? mb_substr(trim($el['caption']), 0, 200) : '';

        $bullets = [];
        if (isset($el['bullets']) && is_array($el['bullets'])) {
            foreach ($el['bullets'] as $b) {
                if (is_string($b) && trim($b) !== '' && count($bullets) < 8) {
                    $bullets[] = trim($b);
                }
            }
        }

        if ($title === '' && $body === '' && $bullets === [] && $caption === '') {
            return null;
        }
        if ($title === '') {
            $title = 'Key point';
        }

        $allowedAccents = ['indigo', 'emerald', 'amber', 'rose', 'violet', 'sky', 'slate'];
        $accent = strtolower(trim((string) ($el['accent'] ?? 'indigo')));
        if (! in_array($accent, $allowedAccents, true)) {
            $accent = 'indigo';
        }

        $icon = '';
        if (isset($el['icon']) && is_string($el['icon'])) {
            $icon = trim($el['icon']);
            $icon = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $icon) ?? '';
            if (str_contains($icon, '<') || str_contains($icon, '>')) {
                $icon = '';
            }
            $icon = mb_substr($icon, 0, 8);
        }

        return [
            'type' => 'card',
            'id' => self::normalizeCanvasElementId($el['id'] ?? null),
            'x' => isset($el['x']) && is_numeric($el['x']) ? (int) $el['x'] : 48,
            'y' => isset($el['y']) && is_numeric($el['y']) ? (int) $el['y'] : 196,
            'width' => isset($el['width']) && is_numeric($el['width']) ? max(120, (int) $el['width']) : 290,
            'height' => isset($el['height']) && is_numeric($el['height']) ? max(100, (int) $el['height']) : 320,
            'title' => $title,
            'body' => $body,
            'bullets' => $bullets,
            'caption' => $caption,
            'accent' => $accent,
            'icon' => $icon,
        ];
    }
}
