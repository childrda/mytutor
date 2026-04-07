<?php

namespace App\Jobs;

use App\Models\LessonGenerationJob;
use App\Services\Ai\LlmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class ProcessLessonGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public string $jobUlid,
    ) {}

    public function handle(): void
    {
        $record = LessonGenerationJob::query()->find($this->jobUlid);
        if (! $record) {
            return;
        }

        $record->update(['status' => 'running']);

        $req = $record->request;
        $requirement = is_array($req) ? (string) ($req['requirement'] ?? '') : '';
        $language = is_array($req) ? (string) ($req['language'] ?? 'en') : 'en';
        $pdf = is_array($req) && isset($req['pdfContent']) ? (string) $req['pdfContent'] : '';
        $pdfExcerpt = $pdf !== '' ? mb_substr($pdf, 0, 12000) : '';

        $baseUrl = (string) config('tutor.default_chat.base_url');
        $apiKey = (string) config('tutor.default_chat.api_key');
        $model = (string) config('tutor.default_chat.model');

        if ($apiKey === '') {
            $record->update([
                'status' => 'failed',
                'error' => 'No LLM API key configured. Set TUTOR_DEFAULT_LLM_API_KEY or OPENAI_API_KEY.',
            ]);

            return;
        }

        $system = 'You are a curriculum designer. Output a single JSON object only, no markdown fences, with keys: '
            .'stage (object: id empty string, name, description, language), '
            .'scenes (array of 3-8 items). Each scene: id (string), type ("slide"|"quiz"), title, order (int), content. '
            .'For type "slide", content MUST be: {type:"slide", canvas:{title (string), width:1000, height:562.5, elements:[...]}}. '
            .'canvas.elements MUST have at least 2 entries. Every element MUST include the string field type with the exact value "text" (required for rendering). '
            .'Shape: {type:"text", id (string), x (number), y (number), width (number), height (number), fontSize (number), text (string with lesson copy, use • for bullets)}. '
            .'Do not omit type or use other type names. Put real teaching content in text fields, not placeholders. '
            .'Optional fallbacks we also read: content.body (string), content.bullets (string array), scene-level notes (string) on the scene object. '
            .'For type "quiz", content: {type:"quiz", questions:[{id, type:"single"|"multiple", prompt, points, options:[{id,label}], correctIds:[], gradingHint}]}. '
            .'Use language: '.$language.'.';

        $user = "Topic / requirement:\n".$requirement;
        if ($pdfExcerpt !== '') {
            $user .= "\n\nSource excerpt:\n".$pdfExcerpt;
        }

        try {
            $raw = LlmClient::chat($baseUrl, $apiKey, $model, [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ], 0.4, 8192);
            $raw = trim($raw);
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $record->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $stage = is_array($decoded['stage'] ?? null) ? $decoded['stage'] : [];
        $rawScenes = is_array($decoded['scenes'] ?? null) ? $decoded['scenes'] : [];
        $stageDesc = is_string($stage['description'] ?? null) ? trim($stage['description']) : '';
        $scenes = [];
        foreach ($rawScenes as $row) {
            $scenes[] = is_array($row)
                ? self::enrichSlideScene($row, $stageDesc, $requirement)
                : $row;
        }
        $id = (string) Str::ulid();
        $stage['id'] = $id;

        $record->update([
            'status' => 'completed',
            'result' => [
                'stage' => $stage,
                'scenes' => $scenes,
            ],
        ]);
    }

    /**
     * Ensures slide scenes have canvas.elements with visible text (models often omit type:"text" or leave elements empty).
     *
     * @param  array<string, mixed>  $scene
     * @return array<string, mixed>
     */
    private static function enrichSlideScene(array $scene, string $stageDescription, string $requirement): array
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
                'id' => isset($el['id']) && is_string($el['id']) && $el['id'] !== '' ? $el['id'] : (string) Str::ulid(),
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
}
