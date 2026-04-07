<?php

namespace App\Services\StudioGeneration;

use App\Services\Ai\LlmClient;
use App\Support\Generate\StudioGenerationSseProtocol;
use Closure;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * LLM-backed helpers for studio authoring endpoints (Phase 4.5).
 */
final class StudioSceneGenerationService
{
    /**
     * @param  Closure(string): void  $emit  Writes one full SSE frame including trailing newlines
     */
    public function streamSceneOutlines(
        Closure $emit,
        string $baseUrl,
        string $apiKey,
        string $model,
        array $body,
    ): void {
        $instruction = $this->requireInstruction($body);
        $maxScenes = $this->clampMaxScenes($body['maxScenes'] ?? null);
        $system = $this->outlineSystemPrompt($maxScenes);
        $user = $this->buildContextUserMessage($body, $instruction, 'scene outlines');

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        $messageId = (string) Str::ulid();

        try {
            $text = LlmClient::chat(
                $baseUrl,
                $apiKey,
                $model,
                $messages,
                (float) config('tutor.studio_generation.temperature', 0.35),
                (int) config('tutor.studio_generation.max_tokens', 4096),
            );
        } catch (Throwable $e) {
            $emit(StudioGenerationSseProtocol::frame(StudioGenerationSseProtocol::TYPE_ERROR, [
                'message' => $e->getMessage(),
                'errorCode' => 'UPSTREAM_ERROR',
            ]));

            return;
        }

        foreach ($this->chunkUtf8($text, (int) config('tutor.studio_generation.sse_chunk_chars', 120)) as $chunk) {
            $emit(StudioGenerationSseProtocol::frame(StudioGenerationSseProtocol::TYPE_TEXT_DELTA, [
                'messageId' => $messageId,
                'content' => $chunk,
            ]));
        }

        try {
            $decoded = $this->decodeJsonObject($text);
            $outlines = $this->normalizeOutlines($decoded['outlines'] ?? null, $maxScenes);
            $emit(StudioGenerationSseProtocol::frame(StudioGenerationSseProtocol::TYPE_DONE, [
                'outlines' => $outlines,
                'raw' => $text,
                'parseError' => false,
            ]));
        } catch (JsonException) {
            $emit(StudioGenerationSseProtocol::frame(StudioGenerationSseProtocol::TYPE_DONE, [
                'outlines' => [],
                'raw' => $text,
                'parseError' => true,
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{actions: list<array<string, mixed>>}
     */
    public function generateSceneActions(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $body,
    ): array {
        $instruction = $this->requireInstruction($body);
        $sceneTitle = trim((string) ($body['sceneTitle'] ?? ''));
        if ($sceneTitle === '') {
            throw new \InvalidArgumentException('sceneTitle is required');
        }
        $sceneType = trim((string) ($body['sceneType'] ?? 'slide'));
        if (! in_array($sceneType, ['slide', 'quiz'], true)) {
            $sceneType = 'slide';
        }

        $system = 'You assist with e-learning authoring. Reply with ONLY valid JSON (no markdown fences). '
            .'Shape: {"actions":[{"id":"string","label":"string","kind":"string","description":"string optional"}]}. '
            .'Use 3–10 concise actions the author might take next (e.g. add_element, refine_text). '
            .'Use lowercase ids with hyphens.';

        $user = $this->buildContextUserMessage($body, $instruction, 'scene actions')
            ."\n\nScene title: {$sceneTitle}\nScene type: {$sceneType}\n";

        $raw = LlmClient::chat(
            $baseUrl,
            $apiKey,
            $model,
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            (float) config('tutor.studio_generation.temperature', 0.35),
            (int) config('tutor.studio_generation.max_tokens', 4096),
        );

        $decoded = $this->decodeJsonObject($raw);

        return ['actions' => $this->normalizeActions($decoded['actions'] ?? null)];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{content: array<string, mixed>}
     */
    public function generateSceneContent(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $body,
    ): array {
        $instruction = $this->requireInstruction($body);
        $sceneTitle = trim((string) ($body['sceneTitle'] ?? ''));
        if ($sceneTitle === '') {
            throw new \InvalidArgumentException('sceneTitle is required');
        }
        $sceneType = trim((string) ($body['sceneType'] ?? 'slide'));
        if (! in_array($sceneType, ['slide', 'quiz'], true)) {
            $sceneType = 'slide';
        }

        $system = 'You assist with e-learning authoring. Reply with ONLY valid JSON (no markdown fences). '
            .'Shape: {"content":{...}}. '
            .'For type slide: {"type":"slide","canvas":{"title":"string","width":1000,"height":562.5,"elements":[]}}. '
            .'For type quiz: {"type":"quiz","questions":[]}. '
            .'Keep content appropriate for the requested scene type.';

        $user = $this->buildContextUserMessage($body, $instruction, 'scene body content')
            ."\n\nScene title: {$sceneTitle}\nScene type: {$sceneType}\n";

        $existing = $body['existingContent'] ?? null;
        if (is_array($existing) && $existing !== []) {
            $user .= 'Existing content (refine or replace as instructed): '.json_encode($existing, JSON_UNESCAPED_UNICODE)."\n";
        }

        $raw = LlmClient::chat(
            $baseUrl,
            $apiKey,
            $model,
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            (float) config('tutor.studio_generation.temperature', 0.35),
            (int) config('tutor.studio_generation.max_tokens', 8192),
        );

        $decoded = $this->decodeJsonObject($raw);
        $content = $decoded['content'] ?? null;
        if (! is_array($content)) {
            throw new RuntimeException('Model returned no content object');
        }

        return ['content' => $content];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{agents: list<array<string, mixed>>}
     */
    public function generateAgentProfiles(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $body,
    ): array {
        $instruction = $this->requireInstruction($body);

        $system = 'You assist with e-learning authoring. Reply with ONLY valid JSON (no markdown fences). '
            .'Shape: {"agents":[{"id":"string","name":"string","persona":"string","color":"#rrggbb optional"}]}. '
            .'Provide 2–4 distinct tutor personas (ids: lowercase letters, digits, hyphen only). '
            .'Persona is one short paragraph for system prompt injection.';

        $user = $this->buildContextUserMessage($body, $instruction, 'multi-agent profiles');

        $raw = LlmClient::chat(
            $baseUrl,
            $apiKey,
            $model,
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            (float) config('tutor.studio_generation.temperature', 0.45),
            (int) config('tutor.studio_generation.max_tokens', 4096),
        );

        $decoded = $this->decodeJsonObject($raw);

        return ['agents' => $this->normalizeAgents($decoded['agents'] ?? null)];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function requireInstruction(array $body): string
    {
        $instruction = isset($body['instruction']) && is_string($body['instruction'])
            ? trim($body['instruction'])
            : '';
        $max = max(20, (int) config('tutor.studio_generation.max_instruction_chars', 8000));
        if ($instruction === '') {
            throw new \InvalidArgumentException('instruction is required');
        }
        if (strlen($instruction) > $max) {
            throw new \InvalidArgumentException('instruction exceeds maximum length ('.$max.' characters)');
        }

        return $instruction;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function buildContextUserMessage(array $body, string $instruction, string $taskLabel): string
    {
        $language = isset($body['language']) && is_string($body['language']) && $body['language'] !== ''
            ? trim($body['language'])
            : 'en';
        $title = isset($body['lessonTitle']) && is_string($body['lessonTitle'])
            ? trim($body['lessonTitle'])
            : '';
        $description = isset($body['lessonDescription']) && is_string($body['lessonDescription'])
            ? trim($body['lessonDescription'])
            : '';

        $parts = [
            'Task: '.$taskLabel,
            'Language: '.$language,
        ];
        if ($title !== '') {
            $parts[] = 'Lesson title: '.$title;
        }
        if ($description !== '') {
            $parts[] = 'Lesson description: '.$description;
        }

        $existing = $body['existingScenes'] ?? null;
        if (is_array($existing) && $existing !== []) {
            $parts[] = 'Existing scenes (JSON): '.json_encode($existing, JSON_UNESCAPED_UNICODE);
        }

        $parts[] = 'Author instruction: '.$instruction;

        return implode("\n", $parts);
    }

    private function outlineSystemPrompt(int $maxScenes): string
    {
        return 'You assist with e-learning authoring. Reply with ONLY valid JSON (no markdown fences). '
            .'Shape: {"outlines":[{"title":"string","type":"slide|quiz","summary":"string"}]}. '
            .'Produce 3–'.$maxScenes.' scene outlines. type must be exactly "slide" or "quiz". '
            .'Summaries should be one or two sentences.';
    }

    private function clampMaxScenes(mixed $raw): int
    {
        $n = is_numeric($raw) ? (int) $raw : 8;

        return max(3, min(16, $n));
    }

    /**
     * @return list<array{title: string, type: string, summary: string}>
     */
    private function normalizeOutlines(mixed $raw, int $maxScenes): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = isset($row['title']) && is_string($row['title']) ? trim($row['title']) : '';
            if ($title === '') {
                continue;
            }
            $type = isset($row['type']) && is_string($row['type']) ? strtolower(trim($row['type'])) : 'slide';
            if (! in_array($type, ['slide', 'quiz'], true)) {
                $type = 'slide';
            }
            $summary = isset($row['summary']) && is_string($row['summary']) ? trim($row['summary']) : '';
            $out[] = [
                'title' => $title,
                'type' => $type,
                'summary' => $summary,
            ];
            if (count($out) >= $maxScenes) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeActions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '';
            $label = isset($row['label']) && is_string($row['label']) ? trim($row['label']) : '';
            if ($id === '' || $label === '') {
                continue;
            }
            $kind = isset($row['kind']) && is_string($row['kind']) ? trim($row['kind']) : 'suggestion';
            $entry = ['id' => $id, 'label' => $label, 'kind' => $kind];
            if (isset($row['description']) && is_string($row['description']) && $row['description'] !== '') {
                $entry['description'] = $row['description'];
            }
            $out[] = $entry;
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeAgents(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = isset($row['id']) && is_string($row['id']) ? strtolower(trim($row['id'])) : '';
            $name = isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '';
            $persona = isset($row['persona']) && is_string($row['persona']) ? trim($row['persona']) : '';
            if ($id === '' || $name === '' || $persona === '') {
                continue;
            }
            $entry = ['id' => $id, 'name' => $name, 'persona' => $persona];
            if (isset($row['color']) && is_string($row['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $row['color'])) {
                $entry['color'] = $row['color'];
            }
            $out[] = $entry;
            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decodeJsonObject(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $raw, $m)) {
            $raw = trim($m[1]);
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new JsonException('Root JSON must be an object');
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function chunkUtf8(string $text, int $size): array
    {
        if ($text === '') {
            return [];
        }
        if ($size < 8) {
            $size = 8;
        }

        return array_values(array_filter(mb_str_split($text, $size), static fn (string $s): bool => $s !== ''));
    }
}
