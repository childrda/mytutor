<?php

namespace App\Services\LessonGeneration;

use App\Jobs\ProcessLessonGenerationJob;
use App\Models\LessonGenerationJob;
use App\Services\Ai\LlmClient;
use App\Support\LessonGeneration\CanvasSpotlightOrdering;
use App\Support\LessonGeneration\ClassroomRolesNormalizer;
use App\Support\LessonGeneration\LessonGenerationPhases;
use App\Support\LessonGeneration\PdfPageImageHydration;
use App\Support\LessonGeneration\PipelineStepException;
use App\Support\LessonGeneration\SlideVisualFallback;
use App\Support\LessonGeneration\StreamingLessonOutlineParser;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Phase 7.3: sequential LLM steps (roles → outline → scene bodies) inside one queue job.
 */
final class OrchestratedLessonGenerationService
{
    private const PLACEHOLDER_SPOTLIGHT_MAX = 3;

    private const PLACEHOLDER_SPOTLIGHT_MS = 3800;

    private const PLACEHOLDER_SPEECH_MAX_CHARS = 2000;

    public function run(LessonGenerationJob $job): void
    {
        $job->refresh();

        $req = $job->request;
        $requirement = is_array($req) ? (string) ($req['requirement'] ?? '') : '';
        $language = is_array($req) ? (string) ($req['language'] ?? 'en') : 'en';
        $pdf = is_array($req) && isset($req['pdfContent']) ? (string) $req['pdfContent'] : '';
        $pdfExcerpt = $pdf !== '' ? mb_substr($pdf, 0, 12000) : '';
        $pdfPageImages = $this->pdfPageImagesFromRequest(is_array($req) ? $req : null);

        $baseUrl = (string) config('tutor.default_chat.base_url');
        $apiKey = (string) config('tutor.default_chat.api_key');

        if ($apiKey === '') {
            $job->update([
                'status' => 'failed',
                'phase' => LessonGenerationPhases::FAILED,
                'progress' => 0,
                'error' => 'No LLM API key configured. Set TUTOR_DEFAULT_LLM_API_KEY or OPENAI_API_KEY.',
            ]);

            return;
        }

        $job->update([
            'status' => 'running',
            'phase' => LessonGenerationPhases::CLASSROOM_ROLES,
            'progress' => 8,
            'phase_detail' => ['message' => 'Generating classroom roles', 'pipelineStep' => 'roles'],
            'error' => null,
        ]);

        $userBase = $this->buildUserContext($requirement, $pdfExcerpt);

        // --- Step 1: roles + stage stub ---
        $systemRoles = 'You are a curriculum designer. Output a single JSON object only, no markdown fences, with keys: '
            .'stage (object: id empty string, name, description, language), '
            .'classroomRoles (object: version 1, personas: array of 3-5). Each persona: id (string), role ("teacher"|"assistant"|"student"), '
            .'name (string), bio (string, one or two sentences), optional accentColor (string "#RRGGBB" hex). '
            .'Use language: '.$language.'.';

        $decodedRoles = $this->chatJson($baseUrl, $apiKey, 'roles', $systemRoles, $userBase, 0.35);

        $stage = is_array($decodedRoles['stage'] ?? null) ? $decodedRoles['stage'] : [];
        $lessonName = is_string($stage['name'] ?? null) && trim((string) $stage['name']) !== ''
            ? trim((string) $stage['name'])
            : 'Generated lesson';

        $rolesPayload = ClassroomRolesNormalizer::normalize(
            is_array($decodedRoles['classroomRoles'] ?? null) ? $decodedRoles['classroomRoles'] : null,
            $requirement,
            $lessonName,
        );

        $job->update([
            'classroom_roles' => $rolesPayload,
            'progress' => 18,
            'phase_detail' => ['message' => 'Roles saved; drafting outline', 'pipelineStep' => 'roles'],
            'result' => array_merge(is_array($job->result) ? $job->result : [], [
                'partial' => true,
                'pipelineStep' => 'roles',
                'stage' => $stage,
                'classroomRoles' => $rolesPayload,
            ]),
        ]);
        $job->refresh();

        // --- Step 2: outline ---
        $job->update([
            'phase' => LessonGenerationPhases::COURSE_OUTLINE,
            'progress' => 28,
            'phase_detail' => ['message' => 'Structuring lesson outline', 'pipelineStep' => 'outline'],
        ]);

        $personasSummary = $this->summarizePersonas($rolesPayload);
        $systemOutline = 'You are a curriculum designer. Output a single JSON object only, no markdown fences, with key: '
            .'outline (array of 3-8 items). Each item: id (string, unique), type ("slide"|"quiz"), title (string), order (int starting 0), '
            .'objective (string, learning goal for that scene), notes (string, optional teacher notes). '
            .'Order must be contiguous. Use language: '.$language.'.';

        $userOutline = $userBase."\n\nClassroom personas (names/roles):\n".$personasSummary;

        $outline = $this->generateOutline($job, $baseUrl, $apiKey, $systemOutline, $userOutline, 0.35, $requirement, $lessonName);

        $job->update([
            'progress' => 38,
            'phase_detail' => ['message' => 'Outline ready; writing slides', 'pipelineStep' => 'outline'],
            'result' => array_merge(is_array($job->result) ? $job->result : [], [
                'partial' => true,
                'pipelineStep' => 'outline',
                'outlineStreaming' => false,
                'outline' => $outline,
            ]),
        ]);
        $job->refresh();

        // --- Step 3: full scenes ---
        $job->update([
            'phase' => LessonGenerationPhases::PAGE_CONTENT,
            'progress' => 48,
            'phase_detail' => ['message' => 'Building slide and quiz content', 'pipelineStep' => 'content'],
        ]);

        $outlineJson = json_encode($outline, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $rolesJson = json_encode($rolesPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $visionBlock = '';
        if ($pdfPageImages !== []) {
            $n = count($pdfPageImages);
            $visionBlock = 'VISION INPUT: The user message includes '.$n.' rasterized page image(s) from their PDF in order (page 1 first). Read labels, diagrams, and photos. '
                .'When a slide should show material from those pages, include {type:"image", id (string), x, y, width, height, src:"pdf_page:1", alt (short description)} '
                .'with src pdf_page:1 for the first image, pdf_page:2 for the second, up to pdf_page:'.$n.' only. After generation the server replaces these with real image data. '
                .'Product-style richness (like polished tutoring apps): combine a strong visual with a structured text block—overview slides are often diagram + "Key ideas" bullets; stage slides alternate image + explanation rather than walls of plain text. '
                .'For overview slides, prefer TWO COLUMNS: LEFT a large image (e.g. x=40, y=175, width=460, height=340) using pdf_page:1 when it shows the main figure; RIGHT a card titled exactly "Key ideas" with bullets from BOTH the excerpt and what you see in the images. '
                .'Leave ~40px horizontal gap between columns. When the outline is stage-based, mirror that pattern: one pdf_page image that fits the stage + a "Key ideas" or compact card for takeaways. '
                .'Optional: a small second card titled "Keywords" or a one-line canvas.footer (e.g. reflection). Quiz outline items stay type "quiz" with multiple-choice UI, not slide cards. ';
        }

        $layoutRules = $pdfPageImages !== []
            ? 'LAYOUT WHEN PDF IMAGES ARE PRESENT (VISION INPUT above): For each type "slide" scene, default to a RICH layout—not text-only. '
                .'(1) Include at least one type "image" with src "pdf_page:N" on most teaching slides when any page visually supports that slide (choose N from 1..'.count($pdfPageImages).'; reuse pages across slides when it makes sense). '
                .'(2) Include a type "card" whose title is exactly "Key ideas" with 3–5 short, concrete bullets grounded in the excerpt and the page images. '
                .'(3) Prefer two columns when one main figure exists: LEFT large image (~x=40,y=175,w=460,h=340), RIGHT "Key ideas" card (~x=520,w=420). Swap left/right if the outline reads better text-first. '
                .'(4) For four parallel items (e.g. cycle stages), you MAY use a 2×2 grid of four cards—but still add a pdf_page image when a single diagram carries the lesson. '
                .'(5) Give every canvas element a stable id (e.g. img_main, card_keyideas, card_keywords) so narration can highlight them. '
                .'Do NOT default to three equal-width concept cards when a pdf_page image can illustrate the slide; reserve three-column cards for abstract comparisons or when images are a poor fit. '
            : 'NO PDF IMAGES: Still build RICH slides—never a single full-slide paragraph in one text box. For EVERY type "slide" (not quiz): '
                .'(1) Include at least one type "image" with src a real https URL to an educational diagram (strongly prefer upload.wikimedia.org/wikipedia/commons thumbnails tied to the slide topic). Do not use example.com, placeholder hosts, or made-up URLs. '
                .'(2) Include a type "card" titled exactly "Key ideas" with 3–5 bullets, OR—when comparing exactly three parallel ideas—THREE type "card" elements side by side (width ~300, x about 32, 340, 648, y ~188) with accents sky, emerald, violet. '
                .'(3) Default layout: LEFT large diagram (~x=40,y=165,w=450,h=340), RIGHT "Key ideas" card (~x=510,w=460). Put the slide heading only in canvas.title/canvas.subtitle—not repeated as a giant text block. '
                .'(4) FORBIDDEN: one text element that carries all teaching prose across the whole canvas; split ideas into cards and/or caption the diagram with short alt text. ';

        $systemContent = 'You are a curriculum designer. Output a single JSON object only, no markdown fences, with key: '
            .'scenes (array). The array MUST have the SAME LENGTH and ORDER as the provided outline. Match each scene id and type from the outline. '
            .$visionBlock
            .'For type "slide", content MUST be: {type:"slide", canvas:{title (string), subtitle (string, optional tagline under title), footer (string, optional "Quick check" or reflection question), width:1000, height:562.5, elements:[...]}}. '
            .'Put the main slide title in canvas.title and a short tagline in canvas.subtitle (e.g. "What you notice every day"). Put a closing prompt in canvas.footer when it fits (e.g. "Quick check: Which example shows evaporation?"). '
            .'Do NOT duplicate the main title in a text element; use text elements only for optional extra callouts (small font, lower on the slide) if needed. '
            .$layoutRules
            .'card fields: {type:"card", id, x, y, width, height, title, bullets (array of strings), caption (string), accent ("sky"|"emerald"|"violet"|"indigo"|"amber"|"rose"|"slate"), icon (one emoji)}. '
            .'image element: {type:"image", id, x, y, width, height, src ("pdf_page:N" or https URL), alt}. '
            .'Optional legacy: body (string) if bullets omitted. text element shape: {type:"text", id, x, y, width, height, fontSize, text}. '
            .'For type "quiz", content: {type:"quiz", questions:[{id, type:"single"|"multiple", prompt, points, options:[{id,label}], correctIds:[], gradingHint}]}. '
            .'Use language: '.$language.'.';

        $userContent = $userBase."\n\nOUTLINE (follow ids, types, order exactly):\n".$outlineJson
            ."\n\nCLASSROOM_ROLES:\n".$rolesJson;

        $decodedContent = $this->chatJsonContentScenes($baseUrl, $apiKey, $systemContent, $userContent, 0.4, $pdfPageImages);

        $rawScenes = is_array($decodedContent['scenes'] ?? null) ? $decodedContent['scenes'] : [];
        $aligned = $this->alignScenesToOutline($rawScenes, $outline);

        $stageDesc = is_string($stage['description'] ?? null) ? trim($stage['description']) : '';
        $scenes = [];
        foreach ($aligned as $row) {
            $scenes[] = is_array($row)
                ? ProcessLessonGenerationJob::enrichSlideScene($row, $stageDesc, $requirement)
                : $row;
        }

        $scenes = PdfPageImageHydration::hydrateScenes($scenes, $pdfPageImages);

        foreach ($scenes as $i => $row) {
            if (is_array($row)) {
                $scenes[$i] = SlideVisualFallback::applyToScene($row, $requirement);
            }
        }

        $stageId = (string) Str::ulid();
        $stage['id'] = $stageId;

        $job->update([
            'progress' => 62,
            'phase_detail' => ['message' => 'Draft scenes ready; wiring narration', 'pipelineStep' => 'content'],
            'result' => array_merge(is_array($job->result) ? $job->result : [], [
                'partial' => true,
                'pipelineStep' => 'content',
                'stage' => $stage,
                'classroomRoles' => $rolesPayload,
                'outline' => $outline,
                'scenes' => $scenes,
            ]),
        ]);
        $job->refresh();

        $job->update([
            'progress' => 72,
            'phase_detail' => ['message' => 'Adding teaching-action placeholders', 'pipelineStep' => 'content'],
        ]);

        $job->update([
            'phase' => LessonGenerationPhases::TEACHING_ACTIONS,
            'progress' => 85,
            'phase_detail' => ['message' => 'Finalizing timeline placeholders', 'pipelineStep' => 'actions'],
        ]);

        $scenes = $this->applyPlaceholderActions($scenes, $rolesPayload, $pdfPageImages !== []);

        $job->update([
            'status' => 'completed',
            'phase' => LessonGenerationPhases::COMPLETED,
            'progress' => 100,
            'phase_detail' => null,
            'result' => [
                'stage' => $stage,
                'scenes' => $scenes,
                'classroomRoles' => $rolesPayload,
                'outline' => $outline,
            ],
        ]);
    }

    private function buildUserContext(string $requirement, string $pdfExcerpt): string
    {
        $user = "Topic / requirement:\n".$requirement;
        if ($pdfExcerpt !== '') {
            $user .= "\n\nSource excerpt:\n".$pdfExcerpt;
        }

        return $user;
    }

    /**
     * @param  array{version: int, personas: list<array<string, mixed>>}  $rolesPayload
     */
    private function summarizePersonas(array $rolesPayload): string
    {
        $lines = [];
        foreach ($rolesPayload['personas'] ?? [] as $p) {
            if (! is_array($p)) {
                continue;
            }
            $role = $p['role'] ?? '?';
            $name = $p['name'] ?? '?';
            $lines[] = "- {$role}: {$name}";
        }

        return $lines === [] ? '(none)' : implode("\n", $lines);
    }

    /**
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string}>
     */
    private function generateOutline(
        LessonGenerationJob $job,
        string $baseUrl,
        string $apiKey,
        string $systemOutline,
        string $userOutline,
        float $temperature,
        string $requirement,
        string $lessonName,
    ): array {
        if (! config('tutor.lesson_generation.stream_outline', true)) {
            $decoded = $this->chatJson($baseUrl, $apiKey, 'outline', $systemOutline, $userOutline, $temperature);

            return $this->finishOutlineFromLlmJson($decoded, $requirement, $lessonName);
        }

        try {
            return $this->generateOutlineStreaming(
                $job,
                $baseUrl,
                $apiKey,
                $systemOutline,
                $userOutline,
                $temperature,
                $requirement,
                $lessonName,
            );
        } catch (Throwable) {
            $decoded = $this->chatJson($baseUrl, $apiKey, 'outline', $systemOutline, $userOutline, $temperature);

            return $this->finishOutlineFromLlmJson($decoded, $requirement, $lessonName);
        }
    }

    /**
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string}>
     */
    private function generateOutlineStreaming(
        LessonGenerationJob $job,
        string $baseUrl,
        string $apiKey,
        string $systemOutline,
        string $userOutline,
        float $temperature,
        string $requirement,
        string $lessonName,
    ): array {
        $model = $this->modelFor('outline');
        $max = $this->maxTokensFor('outline');
        $messages = [
            ['role' => 'system', 'content' => $systemOutline],
            ['role' => 'user', 'content' => $userOutline],
        ];

        $buffer = '';
        $lastEmit = 0.0;
        $lastCount = -1;

        try {
            foreach (LlmClient::streamChat($baseUrl, $apiKey, $model, $messages, $temperature, $max) as $delta) {
                $buffer .= $delta;
                $now = microtime(true);
                $items = StreamingLessonOutlineParser::extractOutlineObjects($buffer);
                $normalized = $this->normalizeOutline($items);
                $n = count($normalized);
                if ($n === 0) {
                    continue;
                }
                if ($n !== $lastCount || ($now - $lastEmit) >= 0.35) {
                    $lastCount = $n;
                    $lastEmit = $now;
                    $job->refresh();
                    $job->update([
                        'progress' => min(37, 28 + min(9, $n * 2)),
                        'phase_detail' => ['message' => 'Structuring lesson outline', 'pipelineStep' => 'outline'],
                        'result' => array_merge(is_array($job->result) ? $job->result : [], [
                            'partial' => true,
                            'pipelineStep' => 'outline',
                            'outlineStreaming' => true,
                            'outline' => $normalized,
                        ]),
                    ]);
                }
            }
        } catch (Throwable $e) {
            if (strlen($buffer) > 30) {
                return $this->outlineFromRawBuffer($buffer, $requirement, $lessonName);
            }

            throw $e;
        }

        return $this->outlineFromRawBuffer($buffer, $requirement, $lessonName);
    }

    /**
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string}>
     */
    private function outlineFromRawBuffer(string $buffer, string $requirement, string $lessonName): array
    {
        $trim = StreamingLessonOutlineParser::stripMarkdownFences($buffer);
        try {
            $decoded = json_decode($trim, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                return $this->finishOutlineFromLlmJson($decoded, $requirement, $lessonName);
            }
        } catch (JsonException) {
            // try incremental extraction
        }

        $items = StreamingLessonOutlineParser::extractOutlineObjects($buffer);
        $outline = $this->normalizeOutline($items);
        if ($outline === []) {
            $outline = $this->defaultOutline($requirement, $lessonName);
        }

        return $outline;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string}>
     */
    private function finishOutlineFromLlmJson(array $decoded, string $requirement, string $lessonName): array
    {
        $outlineRows = is_array($decoded['outline'] ?? null) ? $decoded['outline'] : [];
        $outline = $this->normalizeOutline($outlineRows);
        if ($outline === []) {
            $outline = $this->defaultOutline($requirement, $lessonName);
        }

        return $outline;
    }

    /**
     * @param  list<string>  $pdfPageDataUrls
     * @return array<string, mixed>
     */
    private function chatJsonContentScenes(
        string $baseUrl,
        string $apiKey,
        string $system,
        string $userText,
        float $temperature,
        array $pdfPageDataUrls,
    ): array {
        $model = $this->modelFor('content');
        $max = $this->maxTokensFor('content');
        $useVision = $pdfPageDataUrls !== []
            && (bool) config('tutor.lesson_generation.content_use_pdf_page_images', true);

        try {
            if ($useVision) {
                $parts = [['type' => 'text', 'text' => $userText]];
                foreach (array_values($pdfPageDataUrls) as $url) {
                    if (! is_string($url) || ! str_starts_with($url, 'data:image/')) {
                        continue;
                    }
                    $parts[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $url,
                            'detail' => 'high',
                        ],
                    ];
                }
                $raw = LlmClient::chatWithMessages($baseUrl, $apiKey, $model, [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $parts],
                ], $temperature, $max);
            } else {
                $raw = LlmClient::chat($baseUrl, $apiKey, $model, [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $userText],
                ], $temperature, $max);
            }
        } catch (Throwable $e) {
            throw new PipelineStepException('content', $e->getMessage(), $e);
        }

        $raw = trim($raw);

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PipelineStepException('content', 'Invalid JSON from model: '.$e->getMessage(), $e);
        }

        if (! is_array($decoded)) {
            throw new PipelineStepException('content', 'Model returned non-object JSON root.');
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function pdfPageImagesFromRequest(?array $req): array
    {
        if (! is_array($req) || ! isset($req['pdfPageImages']) || ! is_array($req['pdfPageImages'])) {
            return [];
        }

        $max = max(0, min(4, (int) config('tutor.lesson_generation.max_pdf_page_images', 4)));
        $maxLen = max(10_000, (int) config('tutor.lesson_generation.max_pdf_image_data_url_chars', 700_000));
        $out = [];

        foreach ($req['pdfPageImages'] as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '' || strlen($item) > $maxLen) {
                continue;
            }
            if (
                ! str_starts_with($item, 'data:image/jpeg;base64,')
                && ! str_starts_with($item, 'data:image/png;base64,')
                && ! str_starts_with($item, 'data:image/webp;base64,')
            ) {
                continue;
            }
            $out[] = $item;
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function chatJson(
        string $baseUrl,
        string $apiKey,
        string $step,
        string $system,
        string $user,
        float $temperature,
    ): array {
        $model = $this->modelFor($step);
        $max = $this->maxTokensFor($step);

        try {
            $raw = LlmClient::chat($baseUrl, $apiKey, $model, [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ], $temperature, $max);
        } catch (Throwable $e) {
            throw new PipelineStepException($step, $e->getMessage(), $e);
        }
        $raw = trim($raw);

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PipelineStepException($step, 'Invalid JSON from model: '.$e->getMessage(), $e);
        }

        if (! is_array($decoded)) {
            throw new PipelineStepException($step, 'Model returned non-object JSON root.');
        }

        return $decoded;
    }

    private function modelFor(string $step): string
    {
        $key = match ($step) {
            'roles' => config('tutor.lesson_generation.roles_model'),
            'outline' => config('tutor.lesson_generation.outline_model'),
            'content' => config('tutor.lesson_generation.content_model'),
            default => null,
        };
        $override = is_string($key) && trim($key) !== '' ? trim($key) : null;

        return $override ?? (string) config('tutor.default_chat.model');
    }

    private function maxTokensFor(string $step): int
    {
        return match ($step) {
            'roles' => (int) config('tutor.lesson_generation.roles_max_tokens', 2048),
            'outline' => (int) config('tutor.lesson_generation.outline_max_tokens', 4096),
            'content' => (int) config('tutor.lesson_generation.content_max_tokens', 8192),
            default => 4096,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string}>
     */
    private function normalizeOutline(array $rows): array
    {
        $out = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = isset($row['id']) && is_string($row['id']) && $row['id'] !== '' ? $row['id'] : (string) Str::ulid();
            $typeRaw = $row['type'] ?? 'slide';
            $type = $typeRaw === 'quiz' ? 'quiz' : 'slide';
            $title = isset($row['title']) && is_string($row['title']) && trim($row['title']) !== ''
                ? trim($row['title'])
                : 'Scene '.($i + 1);
            $order = isset($row['order']) && is_numeric($row['order']) ? (int) $row['order'] : $i;
            $objective = isset($row['objective']) && is_string($row['objective']) ? trim($row['objective']) : '';
            $notes = isset($row['notes']) && is_string($row['notes']) ? trim($row['notes']) : '';
            $out[] = [
                'id' => $id,
                'type' => $type,
                'title' => $title,
                'order' => $order,
                'objective' => $objective,
                'notes' => $notes,
            ];
        }
        usort($out, fn ($a, $b) => $a['order'] <=> $b['order']);
        if (count($out) > 8) {
            $out = array_slice($out, 0, 8);
        }
        $reindexed = [];
        foreach (array_values($out) as $idx => $item) {
            $item['order'] = $idx;
            $reindexed[] = $item;
        }

        return $reindexed;
    }

    /**
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string}>
     */
    private function defaultOutline(string $requirement, string $lessonName): array
    {
        $snippet = mb_substr(trim($requirement) !== '' ? trim($requirement) : $lessonName, 0, 80);

        return [
            [
                'id' => (string) Str::ulid(),
                'type' => 'slide',
                'title' => 'Introduction',
                'order' => 0,
                'objective' => 'Frame the topic and goals.',
                'notes' => $snippet,
            ],
            [
                'id' => (string) Str::ulid(),
                'type' => 'slide',
                'title' => 'Core concepts',
                'order' => 1,
                'objective' => 'Teach the main ideas with examples.',
                'notes' => '',
            ],
            [
                'id' => (string) Str::ulid(),
                'type' => 'quiz',
                'title' => 'Check understanding',
                'order' => 2,
                'objective' => 'Verify comprehension.',
                'notes' => '',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rawScenes
     * @param  list<array{id: string, type: string, title: string, order: int, objective: string, notes: string}>  $outline
     * @return list<array<string, mixed>>
     */
    private function alignScenesToOutline(array $rawScenes, array $outline): array
    {
        $result = [];
        foreach ($outline as $idx => $spec) {
            $row = $rawScenes[$idx] ?? null;
            if (! is_array($row)) {
                $row = $this->skeletonSceneFromSpec($spec);
            }
            $row['id'] = $spec['id'];
            $row['type'] = $spec['type'];
            if (! isset($row['title']) || ! is_string($row['title']) || trim($row['title']) === '') {
                $row['title'] = $spec['title'];
            }
            $row['order'] = $spec['order'];
            if ($spec['notes'] !== '' && (! isset($row['notes']) || $row['notes'] === '')) {
                $row['notes'] = $spec['notes'];
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param  array{id: string, type: string, title: string, order: int, objective: string, notes: string}  $spec
     * @return array<string, mixed>
     */
    private function skeletonSceneFromSpec(array $spec): array
    {
        if ($spec['type'] === 'quiz') {
            return [
                'content' => [
                    'type' => 'quiz',
                    'questions' => [
                        [
                            'id' => (string) Str::ulid(),
                            'type' => 'single',
                            'prompt' => 'What did you learn about '.$spec['title'].'?',
                            'points' => 1,
                            'options' => [
                                ['id' => 'a', 'label' => 'A key idea from the lesson'],
                                ['id' => 'b', 'label' => 'Something unrelated'],
                            ],
                            'correctIds' => ['a'],
                            'gradingHint' => '',
                        ],
                    ],
                ],
            ];
        }

        $obj = $spec['objective'] !== '' ? $spec['objective'] : $spec['title'];

        return [
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => $spec['title'],
                    'width' => 1000,
                    'height' => 562.5,
                    'elements' => [
                        [
                            'type' => 'text',
                            'id' => (string) Str::ulid(),
                            'x' => 48,
                            'y' => 64,
                            'width' => 900,
                            'height' => 72,
                            'fontSize' => 28,
                            'text' => $spec['title'],
                        ],
                        [
                            'type' => 'text',
                            'id' => (string) Str::ulid(),
                            'x' => 48,
                            'y' => 160,
                            'width' => 900,
                            'height' => 360,
                            'fontSize' => 22,
                            'text' => '• '.$obj."\n• Take notes and discuss with your classmates.\n• ".($spec['notes'] !== '' ? $spec['notes'] : 'Connect this to the lesson requirement.'),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  array{version: int, personas: list<array<string, mixed>>}  $rolesPayload
     * @return list<array<string, mixed>>
     */
    private function applyPlaceholderActions(array $scenes, array $rolesPayload, bool $hadPdfPageVision = false): array
    {
        if (! config('tutor.lesson_generation.placeholder_narration_actions', true)) {
            return $scenes;
        }

        $teacherId = null;
        foreach ($rolesPayload['personas'] ?? [] as $p) {
            if (! is_array($p)) {
                continue;
            }
            if (($p['role'] ?? '') === 'teacher' && isset($p['id']) && is_string($p['id'])) {
                $teacherId = $p['id'];
                break;
            }
        }
        if ($teacherId === null) {
            $first = $rolesPayload['personas'][0] ?? null;
            if (is_array($first) && isset($first['id']) && is_string($first['id'])) {
                $teacherId = $first['id'];
            }
        }

        $out = [];
        foreach ($scenes as $idx => $scene) {
            if (! is_array($scene)) {
                $out[] = $scene;

                continue;
            }
            $type = $scene['type'] ?? 'slide';
            $actions = $scene['actions'] ?? null;
            if (is_array($actions) && $actions !== []) {
                $out[] = $scene;

                continue;
            }
            if ($type === 'slide' && $teacherId !== null) {
                $title = is_string($scene['title'] ?? null) ? $scene['title'] : 'Slide';
                $scene['actions'] = self::buildDefaultSlideActions($scene, $teacherId, $title, $idx === 0, $hadPdfPageVision);
            }
            $out[] = $scene;
        }

        return $out;
    }

    /**
     * Speech + spotlight pairs so playback can “call out” each region (diagram, key ideas card, etc.).
     *
     * @return list<array<string, mixed>>
     */
    private static function buildDefaultSlideActions(
        array $scene,
        string $teacherId,
        string $sceneTitle,
        bool $isFirstScene,
        bool $hadPdfPageVision,
    ): array {
        $canvas = self::canvasFromScene($scene);
        $orderedIds = $canvas !== null
            ? array_slice(CanvasSpotlightOrdering::spotlightElementIds($canvas), 0, self::PLACEHOLDER_SPOTLIGHT_MAX)
            : [];
        $byId = $canvas !== null ? self::canvasElementsById($canvas) : [];

        $built = [];
        $n = count($orderedIds);
        if ($n === 0) {
            $line = self::fallbackSlideSpeechLine($sceneTitle, $hadPdfPageVision);
            $built[] = self::speechAction($teacherId, 'Opening', $line);
        } else {
            for ($i = 0; $i < $n; $i++) {
                $id = $orderedIds[$i];
                $el = $byId[$id] ?? [];
                $line = self::speechLineForSlidePart($canvas ?? [], $sceneTitle, $hadPdfPageVision, $i, $n, is_array($el) ? $el : []);
                $label = $i === 0 ? 'Opening' : ('Focus '.($i + 1));
                $built[] = self::speechAction($teacherId, $label, $line);
                $built[] = [
                    'id' => (string) Str::ulid(),
                    'type' => 'spotlight',
                    'label' => 'Highlight on slide',
                    'target' => [
                        'kind' => 'element',
                        'elementId' => $id,
                    ],
                    'durationMs' => self::PLACEHOLDER_SPOTLIGHT_MS,
                    'payload' => [],
                ];
            }
        }

        if ($isFirstScene) {
            $built[] = [
                'id' => (string) Str::ulid(),
                'type' => 'interact',
                'label' => 'Partner discussion',
                'mode' => 'pause',
                'prompt' => 'Turn to a partner: what is one takeaway from this opening?',
                'payload' => [],
            ];
        }

        return $built;
    }

    /**
     * @return array<string, mixed>
     */
    private static function speechAction(string $teacherId, string $label, string $text): array
    {
        $text = trim($text);
        $max = self::PLACEHOLDER_SPEECH_MAX_CHARS;
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max - 1).'…';
        }

        return [
            'id' => (string) Str::ulid(),
            'type' => 'speech',
            'label' => $label,
            'text' => $text,
            'personaId' => $teacherId,
            'narrationUrl' => '',
            'payload' => [],
        ];
    }

    private static function fallbackSlideSpeechLine(string $sceneTitle, bool $hadPdfPageVision): string
    {
        $suffix = $hadPdfPageVision ? ' This connects to the pages from your document.' : '';

        return 'We’re on: '.$sceneTitle.'.'.$suffix.' Follow the slide and pause where you need to think.';
    }

    /**
     * @param  array<string, mixed>  $canvas
     * @param  array<string, mixed>  $el
     */
    private static function speechLineForSlidePart(
        array $canvas,
        string $sceneTitle,
        bool $hadPdfPageVision,
        int $partIndex,
        int $partCount,
        array $el,
    ): string {
        $headline = is_string($canvas['title'] ?? null) && trim((string) $canvas['title']) !== ''
            ? trim((string) $canvas['title'])
            : $sceneTitle;
        $subtitle = is_string($canvas['subtitle'] ?? null) ? trim((string) $canvas['subtitle']) : '';
        $footer = is_string($canvas['footer'] ?? null) ? trim((string) $canvas['footer']) : '';

        $type = strtolower(trim((string) ($el['type'] ?? '')));
        $docHint = $hadPdfPageVision ? ' This ties back to your uploaded material.' : '';

        if ($partIndex === 0) {
            $open = 'Here’s '.$headline.'.';
            if ($subtitle !== '') {
                $open .= ' '.$subtitle;
            }
            $digest = self::slideNarrationDigest($canvas);
            if ($digest !== '') {
                $open .= ' Here is the substance on this slide: '.$digest;
            }
            if ($partCount > 1) {
                $open .= ' I’ll walk the slide in a few beats—watch the highlighted area.';
            }
            $open .= $docHint;
            if (($type === 'text' || $type === 'card') && $digest !== '') {
                return $open;
            }

            return self::appendElementCue($open, $type, $el);
        }

        $cue = self::elementNarrationCue($type, $el);
        if ($footer !== '' && $partIndex === $partCount - 1) {
            $cue .= ' Before we leave this slide: '.$footer;
        }

        return $cue;
    }

    private static function appendElementCue(string $prefix, string $type, array $el): string
    {
        $cue = self::elementNarrationCue($type, $el);
        if ($cue === '') {
            return $prefix;
        }

        return $prefix.' '.$cue;
    }

    private static function elementNarrationCue(string $type, array $el): string
    {
        if ($type === 'image') {
            return 'Start with the visual—trace labels, arrows, and what changes from one part to the next.';
        }
        if ($type === 'card') {
            $t = isset($el['title']) && is_string($el['title']) ? trim($el['title']) : '';
            $bullets = isset($el['bullets']) && is_array($el['bullets']) ? $el['bullets'] : [];
            $lines = [];
            foreach ($bullets as $b) {
                if (is_string($b) && trim($b) !== '' && count($lines) < 3) {
                    $lines[] = trim($b);
                }
            }
            $body = isset($el['body']) && is_string($el['body']) ? trim($el['body']) : '';
            if ($lines === [] && $body !== '') {
                $lines[] = mb_substr($body, 0, 180);
            }
            $pack = $lines !== [] ? implode('; ', $lines) : '';
            if ($t !== '' && $pack !== '') {
                return 'Now '.$t.': '.$pack.'.';
            }
            if ($t !== '') {
                return 'Now the '.$t.' box—connect it to what you saw in the image.';
            }

            return 'Now the highlighted card—link these bullets to the big picture.';
        }
        if ($type === 'text') {
            $txt = isset($el['text']) && is_string($el['text']) ? trim($el['text']) : '';
            if ($txt === '') {
                return 'Read the callout on the slide carefully.';
            }
            if (mb_strlen($txt) > 620) {
                $txt = mb_substr($txt, 0, 617).'…';
            }

            return 'Read this with me: '.$txt;
        }

        return '';
    }

    /**
     * Visible teaching copy from the canvas (text + cards) so narration matches what learners read.
     *
     * @param  array<string, mixed>  $canvas
     */
    private static function slideNarrationDigest(array $canvas, int $maxChars = 1100): string
    {
        $elements = isset($canvas['elements']) && is_array($canvas['elements']) ? $canvas['elements'] : [];
        $chunks = [];
        foreach ($elements as $el) {
            if (! is_array($el)) {
                continue;
            }
            $t = strtolower(trim((string) ($el['type'] ?? '')));
            if ($t === 'card') {
                $title = isset($el['title']) && is_string($el['title']) ? trim($el['title']) : '';
                $bullets = isset($el['bullets']) && is_array($el['bullets']) ? $el['bullets'] : [];
                $lines = [];
                foreach ($bullets as $b) {
                    if (is_string($b) && trim($b) !== '' && count($lines) < 6) {
                        $lines[] = trim($b);
                    }
                }
                $body = isset($el['body']) && is_string($el['body']) ? trim($el['body']) : '';
                if ($lines === [] && $body !== '') {
                    $lines[] = mb_substr($body, 0, 240);
                }
                if ($title !== '' && $lines !== []) {
                    $chunks[] = $title.': '.implode('; ', $lines);
                } elseif ($title !== '') {
                    $chunks[] = $title;
                } elseif ($lines !== []) {
                    $chunks[] = implode('; ', $lines);
                }
            }
            if ($t === 'text') {
                $tx = isset($el['text']) && is_string($el['text']) ? trim($el['text']) : '';
                if ($tx !== '') {
                    $chunks[] = $tx;
                }
            }
        }
        if ($chunks === []) {
            $footer = isset($canvas['footer']) && is_string($canvas['footer']) ? trim($canvas['footer']) : '';
            if ($footer === '') {
                return '';
            }

            return mb_strlen($footer) > $maxChars ? mb_substr($footer, 0, $maxChars - 1).'…' : $footer;
        }
        $s = implode(' ', $chunks);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        if (mb_strlen($s) > $maxChars) {
            return mb_substr($s, 0, $maxChars - 1).'…';
        }

        return $s;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function canvasFromScene(array $scene): ?array
    {
        $content = $scene['content'] ?? null;
        if (! is_array($content)) {
            return null;
        }
        $canvas = $content['canvas'] ?? null;

        return is_array($canvas) ? $canvas : null;
    }

    /**
     * @param  array<string, mixed>  $canvas
     * @return array<string, array<string, mixed>>
     */
    private static function canvasElementsById(array $canvas): array
    {
        $map = [];
        $elements = $canvas['elements'] ?? null;
        if (! is_array($elements)) {
            return [];
        }
        foreach ($elements as $el) {
            if (! is_array($el)) {
                continue;
            }
            $id = $el['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $map[$id] = $el;
            }
        }

        return $map;
    }
}
