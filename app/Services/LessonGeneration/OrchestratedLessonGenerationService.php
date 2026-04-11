<?php

namespace App\Services\LessonGeneration;

use App\Jobs\ProcessLessonGenerationJob;
use App\Models\LessonGenerationJob;
use App\Services\Ai\LlmClient;
use App\Services\Ai\LlmExchangeLogger;
use App\Services\Ai\LlmLogContext;
use App\Services\Ai\ModelRegistry;
use App\Services\Ai\ModelRegistryTemplate;
use App\Services\MediaGeneration\GeneratedMediaStorage;
use App\Services\MediaGeneration\ImageGenerationException;
use App\Services\MediaGeneration\OpenAiImageGenerator;
use App\Support\ApiJson;
use App\Support\LessonGeneration\CanvasSpotlightOrdering;
use App\Support\LessonGeneration\ClassroomRolesNormalizer;
use App\Support\LessonGeneration\LessonGenerationPhases;
use App\Support\LessonGeneration\LessonSlideImageModeration;
use App\Support\LessonGeneration\PdfPageImageHydration;
use App\Support\LessonGeneration\PipelineStepException;
use App\Support\LessonGeneration\SlideAiImageHydration;
use App\Support\LessonGeneration\SlideVisualFallback;
use App\Support\LessonGeneration\StreamingLessonOutlineParser;
use App\Support\Tutor\TeachingActionsValidator;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Phase 7.3: sequential LLM steps (roles → outline → scene bodies) inside one queue job.
 */
final class OrchestratedLessonGenerationService
{
    /** @var list<string> */
    private const LAYOUT_HINTS = ['image_card', 'three_cards', 'text_panels', 'image_stacked', 'text_bullets'];

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
        $wantsAiSlideImages = is_array($req) && filter_var($req['enableImageGeneration'] ?? false, FILTER_VALIDATE_BOOL);
        $imageKeyOk = (string) config('tutor.image_generation.api_key') !== ''
            || app(ModelRegistry::class)->hasActive('image');
        // No PDF: gen_img placeholders are resolved when "Image generation" is on.
        // SlideAiImageHydration replaces broken http(s) slide URLs (Commons "No_image_available", example.com, etc.)
        // and unfilled pdf_page refs when Image generation + a resolvable image key (legacy env chain or active
        // models.json image row) — not only for PDF-backed lessons, so no-PDF runs are not stuck on Wikipedia sentinel thumbnails.
        $runAiSlideHydration = $imageKeyOk && $wantsAiSlideImages;

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

        $jobUserId = isset($job->user_id) && is_numeric($job->user_id) ? (int) $job->user_id : null;
        LlmLogContext::push([
            'user_id' => $jobUserId,
            'source' => 'lesson_generation',
            'lesson_generation_job_id' => (string) $job->id,
        ]);
        try {

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
            $systemOutline = $this->buildOutlineSystemPrompt($language);

            $userOutline = $userBase."\n\nClassroom personas (names/roles):\n".$personasSummary
                .$this->buildOutlineLengthUserSuffix();

            $outline = $this->generateOutline($job, $baseUrl, $apiKey, $systemOutline, $userOutline, 0.35, $requirement, $lessonName);
            $outline = $this->ensureOutlineMeetsMinSceneCount(
                $outline,
                $baseUrl,
                $apiKey,
                $systemOutline,
                $userOutline,
                0.35,
                $requirement,
                $lessonName,
            );
            $outline = $this->extendOutlineToConfiguredMinimum(
                $outline,
                $baseUrl,
                $apiKey,
                $requirement,
                $lessonName,
            );

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
            $personasSummary = $this->summarizePersonas($rolesPayload);

            $visionBlock = $this->buildVisionBlock($pdfPageImages);

            if (config('tutor.lesson_generation.content_per_scene', true)) {
                $aligned = $this->generateSceneContentsPerScene(
                    $job,
                    $baseUrl,
                    $apiKey,
                    $userBase,
                    $outline,
                    $personasSummary,
                    $language,
                    $pdfPageImages,
                    $visionBlock,
                    0.4,
                );
            } else {
                $layoutRules = $pdfPageImages !== []
                    ? $this->buildLayoutRules($pdfPageImages, [])
                    : $this->buildBatchedNoPdfLayoutRulesPreamble($outline);
                $systemContent = $this->buildBatchedContentSystemPrompt($language, $layoutRules, $visionBlock);
                $userContent = $userBase."\n\nOUTLINE (follow ids, types, order exactly):\n".$outlineJson
                    ."\n\nCLASSROOM_ROLES:\n".$rolesJson;

                $decodedContent = $this->chatJsonContentScenes($baseUrl, $apiKey, $systemContent, $userContent, 0.4, $pdfPageImages);

                $rawScenes = is_array($decodedContent['scenes'] ?? null) ? $decodedContent['scenes'] : [];
                $aligned = $this->alignScenesToOutline($rawScenes, $outline);
            }

            $stageDesc = is_string($stage['description'] ?? null) ? trim($stage['description']) : '';
            $scenes = [];
            foreach ($aligned as $row) {
                $scenes[] = is_array($row)
                    ? ProcessLessonGenerationJob::enrichSlideScene($row, $stageDesc, $requirement)
                    : $row;
            }

            $scenes = PdfPageImageHydration::hydrateScenes($scenes, $pdfPageImages);

            $renumbered = $this->renumberGenImgPlaceholders($scenes);
            $scenes = $renumbered['scenes'];
            $altMap = $renumbered['altMap'];
            $imageIssues = [];

            if ($wantsAiSlideImages && $altMap !== [] && $imageKeyOk) {
                $resolved = $this->resolveGenImgPlaceholders($scenes, $altMap, $requirement, $language);
                $scenes = $resolved['scenes'];
                foreach ($resolved['failures'] as $row) {
                    $imageIssues[] = array_merge($row, ['severity' => 'error']);
                }
            } else {
                Log::info('[LessonGen] Skipped DALL·E / images/generations (no API calls; no llm_exchange_logs rows for endpoint /v1/images/generations)', [
                    'lesson_generation_job_id' => (string) $job->id,
                    'enable_image_generation_request' => $wantsAiSlideImages,
                    'gen_img_placeholder_count' => count($altMap),
                    'tutor_image_api_key_set' => $imageKeyOk,
                ]);
            }

            foreach ($scenes as $i => $row) {
                if (is_array($row)) {
                    $scenes[$i] = SlideVisualFallback::applyToScene($row, $requirement);
                }
            }

            if ($runAiSlideHydration) {
                // Only run slide image hydration when something still needs work.
                // resolveGenImgPlaceholders already handled gen_img_N; this pass covers failed
                // gen_img retries, pdf_page:*, Wikimedia sentinels, etc. Without the guard (and
                // without treating /storage/... as resolved in srcNeedsGeneration), images from
                // Step A would be sent to the image API again.
                if ($this->slidesNeedImageHydration($scenes)) {
                    $job->update([
                        'phase_detail' => ['message' => 'Generating slide illustrations (image API)', 'pipelineStep' => 'content'],
                    ]);
                    $hydrated = SlideAiImageHydration::hydrateScenes(
                        $scenes,
                        $requirement,
                        $language,
                        function (int $completed, int $planned) use ($job): void {
                            if ($planned <= 0) {
                                return;
                            }
                            $job->refresh();
                            $progress = 60 + (int) floor(($completed / $planned) * 2);

                            $job->update([
                                'progress' => min(61, $progress),
                                'phase_detail' => [
                                    'message' => 'Generating slide illustrations ('.$completed.' / '.$planned.', image API)',
                                    'pipelineStep' => 'content',
                                ],
                            ]);
                        },
                    );
                    $scenes = $hydrated['scenes'];
                    foreach ($hydrated['failures'] as $row) {
                        $imageIssues[] = array_merge($row, ['severity' => 'error']);
                    }
                }
            }

            $unresolvedPlaceholders = $this->countUnresolvedSlideImagePlaceholders($scenes);
            if ($unresolvedPlaceholders > 0 && $wantsAiSlideImages && $imageKeyOk) {
                $imageIssues[] = [
                    'severity' => 'warning',
                    'step' => 'placeholders_remaining',
                    'message' => $unresolvedPlaceholders.' slide image(s) still use placeholders after generation and fallbacks. Open the studio and replace or regenerate those images.',
                    'code' => 'PLACEHOLDERS_REMAINING',
                    'count' => $unresolvedPlaceholders,
                ];
            }

            $stageId = (string) Str::ulid();
            $stage['id'] = $stageId;

            $job->update([
                'progress' => 62,
                'phase_detail' => ['message' => 'Draft scenes ready; wiring narration', 'pipelineStep' => 'content'],
                'result' => array_merge(
                    is_array($job->result) ? $job->result : [],
                    [
                        'partial' => true,
                        'pipelineStep' => 'content',
                        'stage' => $stage,
                        'classroomRoles' => $rolesPayload,
                        'outline' => $outline,
                        'scenes' => $scenes,
                    ],
                    self::resultImageIssuesPayload($imageIssues),
                ),
            ]);
            $job->refresh();

            if (config('tutor.lesson_generation.content_actions_llm', true)) {
                $job->update([
                    'phase' => LessonGenerationPhases::TEACHING_ACTIONS,
                    'progress' => 70,
                    'phase_detail' => ['message' => 'Generating voiceover and teaching actions per scene', 'pipelineStep' => 'actions'],
                ]);
                $scenes = $this->generateSceneActionsPerSceneLlm(
                    $job,
                    $baseUrl,
                    $apiKey,
                    $scenes,
                    $outline,
                    $rolesPayload,
                    $personasSummary,
                    $language,
                    $userBase,
                    $requirement,
                );
                $job->refresh();
                $job->update([
                    'result' => array_merge(
                        is_array($job->result) ? $job->result : [],
                        [
                            'partial' => true,
                            'pipelineStep' => 'actions',
                            'stage' => $stage,
                            'classroomRoles' => $rolesPayload,
                            'outline' => $outline,
                            'scenes' => $scenes,
                        ],
                        self::resultImageIssuesPayload($imageIssues),
                    ),
                ]);
            }

            $job->update([
                'phase' => LessonGenerationPhases::TEACHING_ACTIONS,
                'progress' => 85,
                'phase_detail' => ['message' => 'Finalizing timeline and placeholders', 'pipelineStep' => 'actions'],
                'result' => array_merge(
                    is_array($job->result) ? $job->result : [],
                    [
                        'partial' => true,
                        'pipelineStep' => 'actions',
                        'stage' => $stage,
                        'classroomRoles' => $rolesPayload,
                        'outline' => $outline,
                        'scenes' => $scenes,
                    ],
                    self::resultImageIssuesPayload($imageIssues),
                ),
            ]);
            $job->refresh();

            $scenes = $this->applyPlaceholderActions($scenes, $rolesPayload, $pdfPageImages !== []);

            $job->update([
                'status' => 'completed',
                'phase' => LessonGenerationPhases::COMPLETED,
                'progress' => 100,
                'phase_detail' => null,
                'result' => array_merge(
                    [
                        'stage' => $stage,
                        'scenes' => $scenes,
                        'classroomRoles' => $rolesPayload,
                        'outline' => $outline,
                    ],
                    self::resultImageIssuesPayload($imageIssues),
                ),
            ]);
        } finally {
            LlmLogContext::pop();
        }
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
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>
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
        $streamEnabled = filter_var(config('tutor.lesson_generation.stream_outline', true), FILTER_VALIDATE_BOOL);
        // Streaming + token limits often yield a short prefix of the outline array (e.g. 6–8 objects). When a floor
        // scene count is configured, use one non-streaming completion so the model returns a full JSON array.
        if ($this->outlineSceneBounds()['min'] > 1) {
            $streamEnabled = false;
        }

        if (! $streamEnabled) {
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
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>
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
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>
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
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>
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
     * @param  list<string>  $pdfPageImages
     */
    private function buildVisionBlock(array $pdfPageImages): string
    {
        if ($pdfPageImages === []) {
            return '';
        }
        $n = count($pdfPageImages);

        return 'VISION INPUT: The user message includes '.$n.' rasterized page image(s) from their PDF in order (page 1 first). Read labels, diagrams, and photos. '
            .'When a slide should show material from those pages, include {type:"image", id (string), x, y, width, height, src:"pdf_page:1", alt (short description)} '
            .'with src pdf_page:1 for the first image, pdf_page:2 for the second, up to pdf_page:'.$n.' only. After generation the server replaces these with real image data. '
            .'Product-style richness (like polished tutoring apps): combine a strong visual with a structured text block—overview slides are often diagram + "Key ideas" bullets; stage slides alternate image + explanation rather than walls of plain text. '
            .'For overview slides, prefer TWO COLUMNS: LEFT a large image (e.g. x=40, y=175, width=460, height=340) using pdf_page:1 when it shows the main figure; RIGHT a card titled exactly "Key ideas" with bullets from BOTH the excerpt and what you see in the images. '
            .'Leave ~40px horizontal gap between columns. When the outline is stage-based, mirror that pattern: one pdf_page image that fits the stage + a "Key ideas" or compact card for takeaways. '
            .'Optional: a small second card titled "Keywords" or a one-line canvas.footer (e.g. reflection). Quiz outline items stay type "quiz" with multiple-choice UI, not slide cards. ';
    }

    /**
     * @param  list<string>  $pdfPageImages
     * @param  array<string, mixed>  $spec  Outline row (uses type, layoutHint when no PDF)
     */
    private function buildLayoutRules(array $pdfPageImages, array $spec = []): string
    {
        if ($pdfPageImages !== []) {
            return 'LAYOUT WHEN PDF IMAGES ARE PRESENT (VISION INPUT above): For each type "slide" scene, default to a RICH layout—not text-only. '
                .'(1) Include at least one type "image" with src "pdf_page:N" on most teaching slides when any page visually supports that slide (choose N from 1..'.count($pdfPageImages).'; reuse pages across slides when it makes sense). '
                .'(2) Include a type "card" whose title is exactly "Key ideas" with 3–5 short, concrete bullets grounded in the excerpt and the page images. '
                .'(3) Prefer two columns when one main figure exists: LEFT large image (~x=40,y=175,w=460,h=340), RIGHT "Key ideas" card (~x=520,w=420). Swap left/right if the outline reads better text-first. '
                .'(4) For four parallel items (e.g. cycle stages), you MAY use a 2×2 grid of four cards—but still add a pdf_page image when a single diagram carries the lesson. '
                .'(5) Give every canvas element a stable id (e.g. img_main, card_keyideas, card_keywords) so narration can highlight them. '
                .'Do NOT default to three equal-width concept cards when a pdf_page image can illustrate the slide; reserve three-column cards for abstract comparisons or when images are a poor fit. ';
        }

        if (($spec['type'] ?? '') === 'quiz') {
            return 'This outline item is type "quiz" — produce quiz content only; slide layoutHint does not apply.';
        }

        $hint = isset($spec['layoutHint']) && is_string($spec['layoutHint']) ? $spec['layoutHint'] : 'image_card';
        if (! in_array($hint, self::LAYOUT_HINTS, true)) {
            $hint = 'image_card';
        }

        $imgRule = 'CRITICAL — When this layout uses an image: src MUST be "gen_img_N" placeholders (you may reuse the SAME gen_img_K string on multiple slides if the same diagram applies — the server deduplicates). '
            .'Globally increase N when a NEW distinct image is needed. '
            .'NEVER use "ai_generate:pending" or any https URL — those values are invalid and stripped. ';

        return match ($hint) {
            'three_cards' => 'LAYOUT three_cards — NO type "image" on this slide. '
                .'Create THREE card elements side by side: x ≈ 32, 340, 648; y ≈ 165; width ≈ 295; height ≈ 340. '
                .'Each card: distinct title, 2–4 bullets, different accent (sky|emerald|violet|indigo|amber|rose|slate), emoji icon. '
                .'canvas.subtitle should name what is being compared. Stable ids (e.g. card_a, card_b, card_c).',

            'text_panels' => 'LAYOUT text_panels — NO image. Use ONE large card (x=60, y=150, w=880, h=340) as the main panel with title + 3–5 bullets or a short definition paragraph in bullets; accent sky or emerald. '
                .'Optional: one small type "text" callout (x=60, y=420) for a subtitle or quote. No gen_img placeholder.',

            'text_bullets' => 'LAYOUT text_bullets — NO image. Full-width card panel (x=60, y=150, w=880, h=340) with title and 4–6 concrete bullets; accent amber|emerald|slate. '
                .'Optional second small card bottom-right (x=700, y=400, w=260, h=120) titled "Key term" as a callout.',

            'image_stacked' => $imgRule
                .'LAYOUT image_stacked — LEFT large image (x=40, y=165, w=420, h=340), src gen_img_N, alt = detailed English diagram prompt (one clear subject). '
                .'RIGHT: TWO stacked cards (x=480, y=165, w=490): first card height ≈160, second ≈160, gap ≈20. Each card: distinct title and 2–3 bullets. '
                .'Stable ids: img_main, card_top, card_bottom.',

            default => // image_card
                $imgRule
                .'LAYOUT image_card — LEFT large image (x=40, y=165, w=450, h=340), src gen_img_N, alt = detailed English diagram prompt (one primary subject, explicit label colors for force diagrams). '
                .'RIGHT "Key ideas" card (x=510, y=165, w=450, h=340) with 3–5 concrete bullets. '
                .'RULE — avoid busy collages; one schematic subject per image. Stable ids: img_main, card_keyideas.',
        };
    }

    /**
     * Batched content generation (no PDF): inject per-outline-id layout instructions.
     *
     * @param  list<array<string, mixed>>  $outline
     */
    private function buildBatchedNoPdfLayoutRulesPreamble(array $outline): string
    {
        $blocks = [
            'NO PDF — each slide scene MUST follow the layout rules for its outline id below (from layoutHint). '
                .'Quiz scenes: standard quiz JSON only. '
                .'Reuse the same gen_img_K placeholder string when two slides share one diagram (deduplicated server-side). '
                .'Across the lesson, at most ~40% of slides should use image_card or image_stacked; vary with three_cards, text_panels, text_bullets.',
        ];
        foreach ($outline as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = isset($row['id']) && is_string($row['id']) ? $row['id'] : '?';
            $type = ($row['type'] ?? '') === 'quiz' ? 'quiz' : 'slide';
            if ($type === 'quiz') {
                $blocks[] = '### Outline id '.$id.' (quiz) — multiple-choice quiz UI only.';

                continue;
            }
            $hint = isset($row['layoutHint']) && is_string($row['layoutHint']) ? $row['layoutHint'] : 'image_card';
            $blocks[] = '### Outline id '.$id.' (layoutHint: '.$hint.")\n".$this->buildLayoutRules([], $row);
        }

        return implode("\n\n", $blocks);
    }

    private function buildBatchedContentSystemPrompt(string $language, string $layoutRules, string $visionBlock): string
    {
        return 'You are an expert curriculum designer building visually rich, '
            .'polished educational slides. Output a single JSON object only, no markdown, with key: '
            .'scenes (array matching the outline exactly — same length, same ids, same order).'
            ."\n\n## VISUAL DESIGN RULES (follow these before anything else)\n"
            .$layoutRules
            ."\n\n## ELEMENT SCHEMAS\n"
            .'For type "slide": {type:"slide", canvas:{title, subtitle (short tagline), footer (optional reflection prompt), width:1000, height:562.5, elements:[]}}. '
            .'NEVER put the main title in an element — it goes in canvas.title only. '
            .'For each slide: follow VISUAL DESIGN RULES (some layouts are card-only, no image). Never emit an empty elements array. '
            .'card: {type:"card", id, x, y, width, height, title, bullets:["3-5 concrete bullet strings"], caption, accent:"sky"|"emerald"|"violet"|"indigo"|"amber"|"rose"|"slate", icon:"emoji"}. '
            .'image: {type:"image", id, x, y, width, height, src, alt}. '
            .'src MUST be one of: (a) "pdf_page:N" if a PDF was uploaded, '
            .'(b) "gen_img_1"/"gen_img_2"/etc. (globally unique across all scenes) when no PDF. '
            .'NEVER use "ai_generate:pending" or any URL. '
            .'alt MUST be a detailed English image prompt for this slide; follow the IMAGE ALT RULES in VISUAL DESIGN RULES above (one subject, no busy collages, explicit arrow colors for force diagrams). '
            .$visionBlock
            .'Optional legacy: body (string) on cards if bullets omitted. text element (only for small callouts): {type:"text", id, x, y, width, height, fontSize, text}. '
            .'For type "quiz": {type:"quiz", questions:[{id, type:"single", prompt, points:1, options:[{id,label}], correctIds:[], gradingHint}]}. '
            .'Use language: '.$language.'.';
    }

    private function buildSingleSceneSystemPrompt(string $language, string $layoutRules, string $visionBlock): string
    {
        return 'You are an expert curriculum designer. Generate ONE lesson scene only (OpenMAIC-style: full attention to this slide or quiz). '
            .'Output a single JSON object only, no markdown, with key: scene (one object).'
            ."\n\n## VISUAL DESIGN RULES (follow these before anything else)\n"
            .$layoutRules
            ."\n\n## OUTPUT SHAPE\n"
            .'scene: { type ("slide"|"quiz"), title (string), optional notes (string), content: slide or quiz body }. '
            .'For slide scenes, content = {type:"slide", canvas:{title, subtitle, footer (optional), width:1000, height:562.5, elements:[...]}}. '
            .'canvas.title is the on-slide heading; NEVER put the main title only in a text element. '
            .'Follow VISUAL DESIGN RULES for whether an image is required (card-only layouts have no image). Never emit an empty elements array. '
            .'Expand the outline objective and notes into concrete bullets, labels, and diagram choices—do not output a single short sentence for the whole slide. '
            .'card: {type:"card", id, x, y, width, height, title, bullets (3–5 strings), caption, accent, icon}. '
            .'image: {type:"image", id, x, y, width, height, src, alt}. '
            .'src MUST be one of: (a) "pdf_page:N" if a PDF was uploaded, '
            .'(b) "gen_img_1"/"gen_img_2"/etc. (globally unique across all scenes) when no PDF. '
            .'NEVER use "ai_generate:pending" or any URL. '
            .'alt MUST be a detailed English image prompt for this slide; follow the IMAGE ALT RULES in VISUAL DESIGN RULES above (one subject, no busy collages, explicit arrow colors for force diagrams). '
            .$visionBlock
            .'text elements: optional small callouts only. '
            .'For quiz scenes, content = {type:"quiz", questions:[{id, type:"single", prompt, points:1, options:[{id,label}], correctIds:[], gradingHint}]}. '
            .'Use language: '.$language.'.';
    }

    /**
     * Normalize placeholder image src values to canonical gen_img_1, gen_img_2, … in
     * first-seen order, and build altMap for image generation.
     *
     * Multiple elements that reuse the same original placeholder string (e.g. two slides
     * both use gen_img_1 for one diagram) map to the same canonical id, so altMap has one
     * entry per distinct diagram and resolveGenImgPlaceholders runs one API call for it.
     * Matching is case-insensitive on the original src.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @return array{scenes: list<array<string, mixed>>, altMap: array<string, string>}
     */
    private function renumberGenImgPlaceholders(array $scenes): array
    {
        $counter = 1;
        $altMap = [];
        /** @var array<string, string> $seenMap maps original placeholder src -> canonical gen_img_N */
        $seenMap = [];

        foreach ($scenes as &$scene) {
            if (($scene['type'] ?? '') !== 'slide') {
                continue;
            }
            $content = $scene['content'] ?? null;
            if (! is_array($content) || ($content['type'] ?? '') !== 'slide') {
                continue;
            }
            $canvas = $content['canvas'] ?? null;
            if (! is_array($canvas)) {
                continue;
            }
            $elements = $canvas['elements'] ?? [];
            if (! is_array($elements)) {
                continue;
            }

            foreach ($elements as &$el) {
                if (! is_array($el) || ($el['type'] ?? '') !== 'image') {
                    continue;
                }
                $src = trim((string) ($el['src'] ?? ''));
                if (! preg_match('/^gen_img_\d+$/i', $src) && ! preg_match('/^ai_generate:/i', $src)) {
                    continue;
                }
                $key = strtolower($src);
                if (isset($seenMap[$key])) {
                    $el['src'] = $seenMap[$key];

                    continue;
                }
                $newId = 'gen_img_'.$counter;
                $seenMap[$key] = $newId;
                $el['src'] = $newId;
                $altMap[$newId] = trim((string) ($el['alt'] ?? '')) ?: 'Educational diagram for slide';
                $counter++;
            }
            unset($el);

            $canvas['elements'] = $elements;
            $content['canvas'] = $canvas;
            $scene['content'] = $content;
        }
        unset($scene);

        return ['scenes' => $scenes, 'altMap' => $altMap];
    }

    /**
     * Call DALL-E for each gen_img_N placeholder and replace src with a public URL.
     * Failures are logged — the placeholder stays and SlideVisualFallback may supply an image.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @param  array<string, string>  $altMap  gen_img_N => DALL-E prompt
     * @return array{scenes: list<array<string, mixed>>, failures: list<array{step: string, placeholderId: string, message: string, code: string}>}
     */
    private function resolveGenImgPlaceholders(array $scenes, array $altMap, string $requirement, string $language): array
    {
        $generator = app(OpenAiImageGenerator::class);
        $storage = GeneratedMediaStorage::fromConfig();
        $size = (string) config('tutor.image_generation.default_size', '1792x1024');
        $max = max(1, min(32, (int) config('tutor.lesson_generation.ai_slide_images_max', 12)));

        $resolvedUrls = [];
        $failures = [];
        $n = 0;
        foreach ($altMap as $id => $prompt) {
            if ($n >= $max) {
                break;
            }
            try {
                $result = $generator->generate(
                    prompt: mb_substr($prompt, 0, 3800),
                    size: $size,
                );
                $stored = $storage->storeBinary('lesson-images', 'png', $result['binary']);
                $resolvedUrls[$id] = $stored['url'];
            } catch (ImageGenerationException $e) {
                $recovered = false;
                $lastError = $e;
                if ($e->errorCode === ApiJson::CONTENT_SENSITIVE) {
                    $slideTitle = $this->slideTitleForGenImgPlaceholder($scenes, $id);
                    $retryPrompts = [];
                    $soft = LessonSlideImageModeration::soften($prompt);
                    if ($soft !== '') {
                        $retryPrompts[] = $soft;
                    }
                    $retryPrompts[] = LessonSlideImageModeration::minimalSafePrompt(
                        $slideTitle !== '' ? $slideTitle : 'Lesson slide',
                        $requirement,
                        $language,
                    );

                    foreach ($retryPrompts as $retryPrompt) {
                        try {
                            $result = $generator->generate(
                                prompt: mb_substr($retryPrompt, 0, 3800),
                                size: $size,
                            );
                            $stored = $storage->storeBinary('lesson-images', 'png', $result['binary']);
                            $resolvedUrls[$id] = $stored['url'];
                            $recovered = true;
                            Log::info('[LessonGen] Image generation succeeded after moderation fallback', ['id' => $id]);
                            break;
                        } catch (ImageGenerationException $ex) {
                            $lastError = $ex;
                            Log::warning('[LessonGen] Image moderation fallback attempt failed', [
                                'id' => $id,
                                'message' => $ex->getMessage(),
                                'code' => $ex->errorCode,
                            ]);
                        }
                    }
                }
                if (! $recovered) {
                    $detail = $e->errorCode === ApiJson::CONTENT_SENSITIVE
                        ? $e->getMessage().' Safer and minimal fallbacks were tried; last error: '.$lastError->getMessage()
                        : $e->getMessage();
                    $failures[] = [
                        'step' => 'gen_img',
                        'placeholderId' => $id,
                        'message' => $detail,
                        'code' => $lastError->errorCode,
                    ];
                    Log::warning('[LessonGen] Image generation failed (provider)', [
                        'id' => $id,
                        'message' => $e->getMessage(),
                        'code' => $e->errorCode,
                        'http' => $e->httpStatus,
                    ]);
                }
            } catch (Throwable $e) {
                $failures[] = [
                    'step' => 'gen_img',
                    'placeholderId' => $id,
                    'message' => $e->getMessage(),
                    'code' => 'UNEXPECTED',
                ];
                Log::warning('[LessonGen] Image generation failed', [
                    'id' => $id,
                    'message' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            }
            $n++;
        }

        if ($resolvedUrls === []) {
            return ['scenes' => $scenes, 'failures' => $failures];
        }

        foreach ($scenes as &$scene) {
            if (($scene['type'] ?? '') !== 'slide') {
                continue;
            }
            $content = $scene['content'] ?? null;
            if (! is_array($content) || ($content['type'] ?? '') !== 'slide') {
                continue;
            }
            $canvas = $content['canvas'] ?? null;
            if (! is_array($canvas)) {
                continue;
            }
            $elements = $canvas['elements'] ?? [];
            if (! is_array($elements)) {
                continue;
            }

            foreach ($elements as &$el) {
                if (! is_array($el) || ($el['type'] ?? '') !== 'image') {
                    continue;
                }
                $src = (string) ($el['src'] ?? '');
                if (isset($resolvedUrls[$src])) {
                    $el['src'] = $resolvedUrls[$src];
                }
            }
            unset($el);

            $canvas['elements'] = $elements;
            $content['canvas'] = $canvas;
            $scene['content'] = $content;
        }
        unset($scene);

        return ['scenes' => $scenes, 'failures' => $failures];
    }

    /**
     * Scene / canvas title for the slide that still references this gen_img id (before URLs are applied).
     */
    private function slideTitleForGenImgPlaceholder(array $scenes, string $placeholderId): string
    {
        foreach ($scenes as $scene) {
            if (($scene['type'] ?? '') !== 'slide') {
                continue;
            }
            $content = $scene['content'] ?? null;
            if (! is_array($content) || ($content['type'] ?? '') !== 'slide') {
                continue;
            }
            $canvas = $content['canvas'] ?? null;
            if (! is_array($canvas)) {
                continue;
            }
            $elements = $canvas['elements'] ?? [];
            if (! is_array($elements)) {
                continue;
            }
            foreach ($elements as $el) {
                if (! is_array($el) || ($el['type'] ?? '') !== 'image') {
                    continue;
                }
                if (trim((string) ($el['src'] ?? '')) === $placeholderId) {
                    $canvasTitle = trim((string) ($canvas['title'] ?? ''));
                    $sceneTitle = trim((string) ($scene['title'] ?? ''));

                    return $canvasTitle !== '' ? $canvasTitle : $sceneTitle;
                }
            }
        }

        return '';
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, mixed>
     */
    private static function resultImageIssuesPayload(array $issues): array
    {
        if ($issues === []) {
            return [];
        }

        return ['imageGenerationIssues' => $issues];
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     */
    private function countUnresolvedSlideImagePlaceholders(array $scenes): int
    {
        $n = 0;
        foreach ($scenes as $scene) {
            if (($scene['type'] ?? '') !== 'slide') {
                continue;
            }
            $content = $scene['content'] ?? null;
            if (! is_array($content) || ($content['type'] ?? '') !== 'slide') {
                continue;
            }
            $canvas = $content['canvas'] ?? null;
            if (! is_array($canvas)) {
                continue;
            }
            $elements = $canvas['elements'] ?? [];
            if (! is_array($elements)) {
                continue;
            }
            foreach ($elements as $el) {
                if (! is_array($el) || ($el['type'] ?? '') !== 'image') {
                    continue;
                }
                $src = trim((string) ($el['src'] ?? ''));
                if (preg_match('/^gen_img_\d+$/i', $src) === 1 || preg_match('/^ai_generate:/i', $src) === 1) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /**
     * True when any slide image still needs generation or hydration.
     * Uses {@see SlideAiImageHydration::srcNeedsGeneration()} so orchestration stays aligned with hydration.
     */
    private function slidesNeedImageHydration(array $scenes): bool
    {
        foreach ($scenes as $scene) {
            if (($scene['type'] ?? '') !== 'slide') {
                continue;
            }
            $content = $scene['content'] ?? null;
            if (! is_array($content) || ($content['type'] ?? '') !== 'slide') {
                continue;
            }
            $canvas = $content['canvas'] ?? null;
            if (! is_array($canvas)) {
                continue;
            }
            $elements = $canvas['elements'] ?? [];
            if (! is_array($elements)) {
                continue;
            }
            foreach ($elements as $el) {
                if (! is_array($el) || ($el['type'] ?? '') !== 'image') {
                    continue;
                }
                $src = trim((string) ($el['src'] ?? ''));
                if (SlideAiImageHydration::srcNeedsGeneration($src)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $outline
     * @param  list<string>  $pdfPageImages
     * @return list<array<string, mixed>>
     */
    private function generateSceneContentsPerScene(
        LessonGenerationJob $job,
        string $baseUrl,
        string $apiKey,
        string $userBase,
        array $outline,
        string $personasSummary,
        string $language,
        array $pdfPageImages,
        string $visionBlock,
        float $temperature,
    ): array {
        $total = count($outline);
        if ($total === 0) {
            return [];
        }
        $model = $this->modelFor('content');
        $maxPer = max(1024, (int) config('tutor.lesson_generation.content_max_tokens_per_scene', 6144));
        $concurrent = max(1, min(12, (int) config('tutor.lesson_generation.content_scene_max_concurrent', 4)));
        $useVision = $pdfPageImages !== []
            && (bool) config('tutor.lesson_generation.content_use_pdf_page_images', true);

        $out = [];

        for ($offset = 0; $offset < $total; $offset += $concurrent) {
            $requests = [];
            for ($k = 0; $k < $concurrent && ($offset + $k) < $total; $k++) {
                $idx = $offset + $k;
                $spec = $outline[$idx];
                $sceneLayoutRules = $this->buildLayoutRules($pdfPageImages, is_array($spec) ? $spec : []);
                $system = $this->buildSingleSceneSystemPrompt($language, $sceneLayoutRules, $visionBlock);
                $userText = $this->buildSingleSceneUserText($userBase, $personasSummary, $outline, $idx, $total, $spec);
                $key = 's'.$idx;

                if ($useVision) {
                    $parts = [['type' => 'text', 'text' => $userText]];
                    foreach (array_values($pdfPageImages) as $url) {
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
                    $messages = [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $parts],
                    ];
                } else {
                    $messages = [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $userText],
                    ];
                }

                $requests[] = [
                    'key' => $key,
                    'index' => $idx,
                    'spec' => $spec,
                    'messages' => $messages,
                    'temperature' => $temperature,
                    'max' => $maxPer,
                ];
            }

            $rawByKey = $concurrent <= 1
                ? $this->chatCompletionSequential($baseUrl, $apiKey, $model, $requests)
                : $this->chatCompletionPool($baseUrl, $apiKey, $model, $requests);

            foreach ($requests as $req) {
                $raw = $rawByKey[$req['key']] ?? '';
                $parsed = $this->parseSingleSceneJson($raw, $req['spec']);
                if ($parsed === null) {
                    $row = $this->skeletonSceneFromSpec($req['spec']);
                } else {
                    $row = $parsed;
                }
                $out[$req['index']] = $this->finalizeSceneRowFromLlm($row, $req['spec']);
            }

            $done = min($total, $offset + $concurrent);
            $job->refresh();
            $job->update([
                'progress' => 48 + (int) floor(($done / max(1, $total)) * 12),
                'phase_detail' => [
                    'message' => 'Building slide and quiz content ('.$done.' / '.$total.')',
                    'pipelineStep' => 'content',
                ],
            ]);
        }

        ksort($out);

        return array_values($out);
    }

    /**
     * @param  list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>  $outline
     * @param  array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}  $spec
     */
    private function buildSingleSceneUserText(
        string $userBase,
        string $personasSummary,
        array $outline,
        int $index,
        int $total,
        array $spec,
    ): string {
        $specJson = json_encode($spec, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $lines = [];
        $lines[] = 'Classroom personas (names/roles):';
        $lines[] = $personasSummary;
        $lines[] = '';
        $lines[] = 'Lesson position: scene '.($index + 1).' of '.$total.'.';
        if ($index > 0) {
            $prevTitle = $outline[$index - 1]['title'] ?? '';
            if ($prevTitle !== '') {
                $lines[] = 'Previous scene title: '.$prevTitle;
            }
        }
        if ($index < $total - 1) {
            $nextTitle = $outline[$index + 1]['title'] ?? '';
            if ($nextTitle !== '') {
                $lines[] = 'Next scene title: '.$nextTitle;
            }
        }
        $lines[] = '';
        $lines[] = 'OUTLINE_SCENE_ID: '.$spec['id'];
        $lines[] = 'Generate rich content for THIS outline item only. Turn objective and notes into detailed slide elements (or a full quiz).';
        $lines[] = 'Outline item JSON:';
        $lines[] = $specJson;
        $lines[] = '';
        $lines[] = $userBase;

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{key: string, index: int, spec: array<string, mixed>, messages: list<array<string, mixed>>, temperature: float, max: int}>  $requests
     * @return array<string, string>
     */
    private function chatCompletionPool(string $baseUrl, string $apiKey, string $model, array $requests): array
    {
        $resolved = LlmClient::openAiRegistryChatEndpointAndHeaders($baseUrl, $apiKey, $model, false);
        $url = $resolved['url'] ?? rtrim($baseUrl, '/').'/chat/completions';
        $registryHeaders = $resolved['headers'] ?? null;
        $logger = app(LlmExchangeLogger::class);
        $log = $logger->enabled();
        $ctx = LlmExchangeLogger::mergeContext([]);
        if ($log) {
            foreach ($requests as $i => $req) {
                $cid = (string) Str::ulid();
                $requests[$i]['_llm_cid'] = $cid;
                $logger->record('sent', $cid, $ctx['user_id'], $ctx['source'], array_merge([
                    'model' => $model,
                    'messages' => $req['messages'],
                    'temperature' => $req['temperature'],
                ], LlmClient::completionLimitPayload($req['max'])), '/chat/completions', null, ['pool_key' => $req['key']]);
            }
        }

        $responses = Http::pool(function (Pool $pool) use ($url, $apiKey, $model, $requests, $registryHeaders) {
            foreach ($requests as $req) {
                $pending = $pool->as($req['key'])->timeout(300);
                if ($registryHeaders !== null) {
                    $pending = $pending->withHeaders($registryHeaders);
                } else {
                    $pending = $pending->withToken($apiKey, 'Bearer')->acceptJson();
                }
                $pending->post($url, array_merge([
                    'model' => $model,
                    'messages' => $req['messages'],
                    'temperature' => $req['temperature'],
                ], LlmClient::completionLimitPayload($req['max'])));
            }
        });

        $out = [];
        foreach ($requests as $req) {
            $key = $req['key'];
            $r = $responses[$key] ?? null;
            if ($log && isset($req['_llm_cid']) && is_string($req['_llm_cid'])) {
                $cid = $req['_llm_cid'];
                if ($r instanceof Response && $r->successful()) {
                    $json = $r->json();
                    $logger->record('received', $cid, $ctx['user_id'], $ctx['source'], is_array($json) ? $json : ['raw' => $r->body()], '/chat/completions', $r->status(), ['pool_key' => $key]);
                } elseif ($r instanceof Response) {
                    $logger->record('received', $cid, $ctx['user_id'], $ctx['source'], ['body' => $r->body()], '/chat/completions', $r->status(), ['pool_key' => $key, 'error' => true]);
                } else {
                    $logger->record('received', $cid, $ctx['user_id'], $ctx['source'], ['error' => 'no_response'], '/chat/completions', null, ['pool_key' => $key, 'error' => true]);
                }
            }
            if ($r instanceof Response && $r->successful()) {
                $c = data_get($r->json(), 'choices.0.message.content');
                $out[$key] = is_string($c) ? trim($c) : '';
            } else {
                $out[$key] = '';
            }
        }

        return $out;
    }

    /**
     * Sequential completions (predictable order for tests; avoids Http::pool + fake ordering issues).
     *
     * @param  list<array{key: string, messages: list<array<string, mixed>>, temperature: float, max: int}>  $requests
     * @return array<string, string>
     */
    private function chatCompletionSequential(string $baseUrl, string $apiKey, string $model, array $requests): array
    {
        $out = [];
        foreach ($requests as $req) {
            try {
                $msgs = $req['messages'];
                $multimodal = false;
                foreach ($msgs as $m) {
                    $c = $m['content'] ?? null;
                    if (is_array($c) && $c !== [] && isset($c[0]) && is_array($c[0]) && isset($c[0]['type'])) {
                        $multimodal = true;
                        break;
                    }
                }
                if ($multimodal) {
                    $raw = LlmClient::chatWithMessages(
                        $baseUrl,
                        $apiKey,
                        $model,
                        $msgs,
                        $req['temperature'],
                        $req['max'],
                    );
                } else {
                    $raw = LlmClient::chat(
                        $baseUrl,
                        $apiKey,
                        $model,
                        $msgs,
                        $req['temperature'],
                        $req['max'],
                    );
                }
                $out[$req['key']] = trim($raw);
            } catch (Throwable) {
                $out[$req['key']] = '';
            }
        }

        return $out;
    }

    /**
     * @param  array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}  $spec
     */
    private function parseSingleSceneJson(string $raw, array $spec): ?array
    {
        if ($raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($decoded)) {
            return null;
        }
        $row = $decoded['scene'] ?? null;
        if (! is_array($row)) {
            return null;
        }
        $content = $row['content'] ?? null;
        if (! is_array($content)) {
            return null;
        }
        $expect = $spec['type'] === 'quiz' ? 'quiz' : 'slide';
        if (($content['type'] ?? '') !== $expect) {
            return null;
        }
        if ($expect === 'slide') {
            $canvas = $content['canvas'] ?? null;
            if (! is_array($canvas)) {
                return null;
            }
            $els = $canvas['elements'] ?? null;
            if (! is_array($els) || $els === []) {
                return null;
            }
        }
        if ($expect === 'quiz') {
            $qs = $content['questions'] ?? null;
            if (! is_array($qs) || $qs === []) {
                return null;
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}  $spec
     * @return array<string, mixed>
     */
    private function finalizeSceneRowFromLlm(array $row, array $spec): array
    {
        $row['id'] = $spec['id'];
        $row['type'] = $spec['type'];
        if (! isset($row['title']) || ! is_string($row['title']) || trim($row['title']) === '') {
            $row['title'] = $spec['title'];
        }
        $row['order'] = $spec['order'];
        if ($spec['notes'] !== '' && (! isset($row['notes']) || $row['notes'] === '')) {
            $row['notes'] = $spec['notes'];
        }

        return $row;
    }

    /**
     * OpenMAIC-style step 2: one LLM call per slide scene for voiceover + spotlight sequence.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>  $outline
     * @param  array{version: int, personas: list<array<string, mixed>>}  $rolesPayload
     * @return list<array<string, mixed>>
     */
    private function generateSceneActionsPerSceneLlm(
        LessonGenerationJob $job,
        string $baseUrl,
        string $apiKey,
        array $scenes,
        array $outline,
        array $rolesPayload,
        string $personasSummary,
        string $language,
        string $userBase,
        string $requirement,
    ): array {
        $teacher = $this->teacherPersonaForActions($rolesPayload);
        $teacherId = $teacher['id'];
        if ($teacherId === '') {
            return $scenes;
        }

        $outlineById = [];
        foreach ($outline as $spec) {
            if (isset($spec['id']) && is_string($spec['id']) && $spec['id'] !== '') {
                $outlineById[$spec['id']] = $spec;
            }
        }

        $system = $this->buildActionsSystemPrompt($language, $teacher['name']);
        $model = $this->modelFor('actions');
        $maxPer = max(512, (int) config('tutor.lesson_generation.actions_max_tokens_per_scene', 3072));
        $concurrent = max(1, min(12, (int) config('tutor.lesson_generation.actions_scene_max_concurrent', 4)));

        $slideIndexes = [];
        foreach ($scenes as $i => $scene) {
            if (is_array($scene) && ($scene['type'] ?? 'slide') === 'slide') {
                $sid = isset($scene['id']) && is_string($scene['id']) ? $scene['id'] : '';
                if ($sid !== '' && isset($outlineById[$sid])) {
                    $slideIndexes[] = $i;
                }
            }
        }
        $totalSlide = count($slideIndexes);
        $doneSlide = 0;

        $out = $scenes;
        for ($p = 0; $p < $totalSlide; $p += $concurrent) {
            $chunkIdx = array_slice($slideIndexes, $p, $concurrent);
            $requests = [];
            foreach ($chunkIdx as $idx) {
                $scene = $out[$idx];
                if (! is_array($scene)) {
                    continue;
                }
                $sid = isset($scene['id']) && is_string($scene['id']) ? $scene['id'] : '';
                $spec = $outlineById[$sid] ?? null;
                if (! is_array($spec)) {
                    continue;
                }
                $userText = $this->buildActionsUserText(
                    $userBase,
                    $requirement,
                    $personasSummary,
                    $spec,
                    $scene,
                );
                $requests[] = [
                    'key' => 'a'.$idx,
                    'index' => $idx,
                    'scene' => $scene,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $userText],
                    ],
                    'temperature' => 0.45,
                    'max' => $maxPer,
                ];
            }

            if ($requests === []) {
                break;
            }

            $rawByKey = $concurrent <= 1
                ? $this->chatCompletionSequential($baseUrl, $apiKey, $model, $requests)
                : $this->chatCompletionPool($baseUrl, $apiKey, $model, $requests);

            foreach ($requests as $req) {
                $raw = $rawByKey[$req['key']] ?? '';
                $parsed = $this->parseLlmActionsJson($raw, $teacherId, $req['scene']);
                if ($parsed !== []) {
                    $errs = TeachingActionsValidator::messagesFor(
                        $parsed,
                        is_array($req['scene']['content'] ?? null) ? $req['scene']['content'] : null,
                        ['classroomRoles' => $rolesPayload],
                        'slide',
                    );
                    if ($errs === []) {
                        $out[$req['index']]['actions'] = $parsed;
                    }
                }
                $doneSlide++;
            }

            $job->refresh();
            $job->update([
                'progress' => 70 + (int) floor(($doneSlide / max(1, $totalSlide)) * 12),
                'phase_detail' => [
                    'message' => 'Teaching actions ('.$doneSlide.' / '.$totalSlide.' slides)',
                    'pipelineStep' => 'actions',
                ],
            ]);
        }

        return $out;
    }

    /**
     * @param  array{version: int, personas: list<array<string, mixed>>}  $rolesPayload
     * @return array{id: string, name: string}
     */
    private function teacherPersonaForActions(array $rolesPayload): array
    {
        foreach ($rolesPayload['personas'] ?? [] as $p) {
            if (! is_array($p)) {
                continue;
            }
            if (($p['role'] ?? '') === 'teacher' && isset($p['id']) && is_string($p['id']) && $p['id'] !== '') {
                $name = isset($p['name']) && is_string($p['name']) ? trim($p['name']) : 'Teacher';

                return ['id' => $p['id'], 'name' => $name !== '' ? $name : 'Teacher'];
            }
        }
        $first = $rolesPayload['personas'][0] ?? null;
        if (is_array($first) && isset($first['id']) && is_string($first['id']) && $first['id'] !== '') {
            $name = isset($first['name']) && is_string($first['name']) ? trim($first['name']) : 'Teacher';

            return ['id' => $first['id'], 'name' => $name !== '' ? $name : 'Teacher'];
        }

        return ['id' => '', 'name' => 'Teacher'];
    }

    private function buildActionsSystemPrompt(string $language, string $teacherDisplayName): string
    {
        return 'You are an instructional designer creating a teaching timeline for ONE slide scene. '
            .'The teacher presenting is named '.$teacherDisplayName.'. '
            .'Output a single JSON object only, no markdown, with key: actions (array). '
            .'Each item is an object with field "type": one of speech, spotlight, interact. '
            ."\n\n"
            .'speech: { "type":"speech", "label": string (short step title), "text": string }. '
            .'Write "text" as full voiceover script: multiple sentences, conversational, engaging for students—like a presenter talking through the slide. '
            .'Use hooks, brief explanations, and transitions; do not output only a single short phrase. '
            .'Do NOT include JSON inside the speech text. Language: '.$language.'. '
            ."\n\n"
            .'spotlight: { "type":"spotlight", "label": string, "target": { "elementId": string }, "durationMs": number (1500–8000) }. '
            .'elementId MUST be copied exactly from the element list provided—only spotlight ids that exist. '
            .'Order: usually spotlight before the speech that discusses that element (focus, then explain). '
            ."\n\n"
            .'interact (optional, at most one per scene): { "type":"interact", "label": string, "mode": "pause", "prompt": string } for partner reflection or quick check. '
            ."\n\n"
            .'Include at least one speech that introduces the slide topic, and enough speech steps to cover main bullets/images. '
            .'Typical length: 4–12 actions alternating spotlight + speech where helpful.';
    }

    /**
     * @param  array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}  $spec
     * @param  array<string, mixed>  $scene
     */
    private function buildActionsUserText(
        string $userBase,
        string $requirement,
        string $personasSummary,
        array $spec,
        array $scene,
    ): string {
        $elementLines = [];
        $canvas = self::canvasFromScene($scene);
        if ($canvas !== null) {
            $els = $canvas['elements'] ?? [];
            if (is_array($els)) {
                foreach ($els as $el) {
                    if (! is_array($el)) {
                        continue;
                    }
                    $id = isset($el['id']) && is_string($el['id']) ? $el['id'] : '';
                    if ($id === '') {
                        continue;
                    }
                    $t = strtolower(trim((string) ($el['type'] ?? '')));
                    $hint = $id.' ('.$t.')';
                    if ($t === 'card') {
                        $title = isset($el['title']) && is_string($el['title']) ? trim($el['title']) : '';
                        $hint .= $title !== '' ? ' title: '.$title : '';
                    }
                    if ($t === 'image') {
                        $hint .= ' [diagram/image]';
                    }
                    $elementLines[] = $hint;
                }
            }
        }

        $sceneForPrompt = $this->sanitizeSceneForActionsPrompt($scene);
        $sceneJson = json_encode($sceneForPrompt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $specJson = json_encode($spec, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $lines = [];
        $lines[] = 'Course context:';
        $lines[] = $userBase;
        $lines[] = '';
        $lines[] = 'Requirement summary: '.mb_substr(trim($requirement) !== '' ? trim($requirement) : '(topic only)', 0, 500);
        $lines[] = '';
        $lines[] = 'Personas:';
        $lines[] = $personasSummary;
        $lines[] = '';
        $lines[] = 'Outline item for this scene:';
        $lines[] = $specJson;
        $lines[] = '';
        $lines[] = 'Valid canvas element ids for spotlight (use only these):';
        $lines[] = $elementLines === [] ? '(none — use speech only)' : implode("\n", $elementLines);
        $lines[] = '';
        $lines[] = 'Scene JSON (slide content; image src may be shortened):';
        $lines[] = $sceneJson;

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $scene
     * @return array<string, mixed>
     */
    private function sanitizeSceneForActionsPrompt(array $scene): array
    {
        $copy = json_decode(json_encode($scene, JSON_THROW_ON_ERROR), true);
        if (! is_array($copy)) {
            return $scene;
        }
        $content = $copy['content'] ?? null;
        if (is_array($content) && ($content['type'] ?? '') === 'slide') {
            $canvas = $content['canvas'] ?? null;
            if (is_array($canvas) && isset($canvas['elements']) && is_array($canvas['elements'])) {
                foreach ($canvas['elements'] as $i => $el) {
                    if (! is_array($el)) {
                        continue;
                    }
                    $src = isset($el['src']) && is_string($el['src']) ? $el['src'] : '';
                    if (str_starts_with($src, 'data:image') && strlen($src) > 120) {
                        $canvas['elements'][$i]['src'] = '[base64 image omitted; use element id for spotlight]';
                    }
                }
                $content['canvas'] = $canvas;
                $copy['content'] = $content;
            }
        }

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $scene
     * @return list<array<string, mixed>>
     */
    private function parseLlmActionsJson(string $raw, string $teacherId, array $scene): array
    {
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (! is_array($decoded)) {
            return [];
        }
        $actions = $decoded['actions'] ?? null;
        if (! is_array($actions)) {
            return [];
        }

        $idSet = [];
        $idByLower = [];
        $canvas = self::canvasFromScene($scene);
        if ($canvas !== null) {
            foreach ($canvas['elements'] ?? [] as $el) {
                if (is_array($el) && isset($el['id']) && is_string($el['id']) && $el['id'] !== '') {
                    $idSet[$el['id']] = true;
                    $idByLower[strtolower($el['id'])] = $el['id'];
                }
            }
        }

        $out = [];
        $interactCount = 0;
        foreach ($actions as $a) {
            if (! is_array($a)) {
                continue;
            }
            $type = strtolower(trim((string) ($a['type'] ?? '')));
            if ($type === 'speech') {
                $text = isset($a['text']) && is_string($a['text']) ? trim($a['text']) : '';
                if ($text === '') {
                    continue;
                }
                if (mb_strlen($text) > 6000) {
                    $text = mb_substr($text, 0, 5999).'…';
                }
                $label = isset($a['label']) && is_string($a['label']) ? trim($a['label']) : 'Narration';
                if ($label === '') {
                    $label = 'Narration';
                }
                $out[] = [
                    'id' => (string) Str::ulid(),
                    'type' => 'speech',
                    'label' => mb_substr($label, 0, 120),
                    'text' => $text,
                    'personaId' => $teacherId,
                    'narrationUrl' => '',
                    'payload' => [],
                ];
            } elseif ($type === 'spotlight') {
                $target = $a['target'] ?? null;
                if (! is_array($target)) {
                    continue;
                }
                $eid = isset($target['elementId']) && is_string($target['elementId']) ? trim($target['elementId']) : '';
                if ($eid === '' || $idSet === []) {
                    continue;
                }
                if (! isset($idSet[$eid])) {
                    $canon = $idByLower[strtolower($eid)] ?? null;
                    if ($canon !== null) {
                        $eid = $canon;
                    }
                }
                if (! isset($idSet[$eid])) {
                    continue;
                }
                $label = isset($a['label']) && is_string($a['label']) ? trim($a['label']) : 'Highlight';
                $ms = isset($a['durationMs']) && is_numeric($a['durationMs']) ? (int) $a['durationMs'] : 3800;
                $ms = min(12_000, max(1500, $ms));
                $out[] = [
                    'id' => (string) Str::ulid(),
                    'type' => 'spotlight',
                    'label' => mb_substr($label !== '' ? $label : 'Highlight', 0, 120),
                    'target' => [
                        'kind' => 'element',
                        'elementId' => $eid,
                    ],
                    'durationMs' => $ms,
                    'payload' => [],
                ];
            } elseif ($type === 'interact') {
                if ($interactCount >= 1) {
                    continue;
                }
                $mode = strtolower(trim((string) ($a['mode'] ?? '')));
                if ($mode !== 'pause' && $mode !== 'quiz_gate') {
                    continue;
                }
                $prompt = isset($a['prompt']) && is_string($a['prompt']) ? trim($a['prompt']) : '';
                if ($prompt === '') {
                    continue;
                }
                $label = isset($a['label']) && is_string($a['label']) ? trim($a['label']) : 'Activity';
                $out[] = [
                    'id' => (string) Str::ulid(),
                    'type' => 'interact',
                    'label' => mb_substr($label !== '' ? $label : 'Activity', 0, 120),
                    'mode' => $mode,
                    'prompt' => mb_substr($prompt, 0, 2000),
                    'payload' => [],
                ];
                $interactCount++;
            }
        }

        return $out;
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
        ?int $maxCompletionTokens = null,
    ): array {
        $model = $this->modelFor($step);
        $max = $maxCompletionTokens ?? $this->maxTokensFor($step);

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
        // 1. Per-step env override always wins.
        $key = match ($step) {
            'roles' => config('tutor.lesson_generation.roles_model'),
            'outline' => config('tutor.lesson_generation.outline_model'),
            'content' => config('tutor.lesson_generation.content_model'),
            'actions' => config('tutor.lesson_generation.actions_model'),
            default => null,
        };
        $override = is_string($key) && trim($key) !== '' ? trim($key) : null;
        if ($override !== null) {
            return $override;
        }

        // 2. Active registry LLM: concrete request_format.model (no placeholders) wins; {model|default}
        //    defers to TUTOR_DEFAULT_LLM_MODEL when set so generic "openai" rows do not ignore env.
        $registry = app(ModelRegistry::class);
        if ($registry->hasActive('llm')) {
            try {
                $entry = $registry->activeEntry('llm');
                $rf = $entry['request_format'] ?? null;

                if (is_array($rf) && isset($rf['model']) && is_string($rf['model'])) {
                    $modelField = trim($rf['model']);

                    if ($modelField !== '' && ! str_contains($modelField, '{')) {
                        return $modelField;
                    }

                    if (preg_match('/^\{model\|([^}]+)\}$/', $modelField, $m)) {
                        $configModel = trim((string) config('tutor.default_chat.model', ''));
                        if ($configModel !== '') {
                            return $configModel;
                        }
                        $templateDefault = trim($m[1]);
                        if ($templateDefault !== '') {
                            return $templateDefault;
                        }
                    }
                }

                $fromRow = ModelRegistryTemplate::defaultModelIdFromEntry($entry);
                if (is_string($fromRow) && $fromRow !== '' && ! str_contains($fromRow, '{')) {
                    return $fromRow;
                }
            } catch (Throwable) {
                // Registry read failed — fall through to default_chat config.
            }
        }

        // 3. Final fallback: TUTOR_DEFAULT_LLM_MODEL (or tutor.default_chat default).
        return (string) config('tutor.default_chat.model');
    }

    private function maxTokensFor(string $step): int
    {
        return match ($step) {
            'roles' => (int) config('tutor.lesson_generation.roles_max_tokens', 2048),
            'outline' => $this->outlineMaxCompletionTokens(),
            'content' => (int) config('tutor.lesson_generation.content_max_tokens', 8192),
            'actions' => (int) config('tutor.lesson_generation.actions_max_tokens_per_scene', 3072),
            default => 4096,
        };
    }

    /**
     * Large outlines need more completion tokens; streaming otherwise stops mid-array (few scenes parsed).
     */
    private function outlineMaxCompletionTokens(): int
    {
        $configured = max(512, (int) config('tutor.lesson_generation.outline_max_tokens', 4096));
        $sceneCap = max(1, (int) config('tutor.lesson_generation.outline_max_scenes', 20));
        $fromScenes = 900 + $sceneCap * 220;

        return max($configured, min(32768, $fromScenes));
    }

    /**
     * @return array{min: int, max: int}
     */
    private function outlineSceneBounds(): array
    {
        $maxRaw = (int) config('tutor.lesson_generation.outline_max_scenes', 20);
        $minRaw = (int) config('tutor.lesson_generation.outline_min_scenes', 1);
        $max = max(1, $maxRaw);
        $min = max(1, $minRaw);
        if ($min > $max) {
            $min = $max;
        }

        return ['min' => $min, 'max' => $max];
    }

    private function buildOutlineSystemPrompt(string $language): string
    {
        ['min' => $min, 'max' => $max] = $this->outlineSceneBounds();
        $range = $min === $max
            ? (string) $max
            : $min.'–'.$max;
        $hints = implode('|', self::LAYOUT_HINTS);

        return 'You are a curriculum designer. Output a single JSON object only, no markdown fences, with key: '
            .'outline (array). HARD RULE: the array length MUST be between '.$min.' and '.$max.' items inclusive. '
            .'Fewer than '.$min.' or more than '.$max.' is invalid. '
            .'Each item is one scene (slide or quiz) with its own title and learning objective—split the lesson across enough scenes to satisfy the minimum; avoid repeating the same objective on multiple items. '
            .'If the user requirement caps length (e.g. "max 25 slides"), stay at or under that cap but never below '.$min.' unless they explicitly request fewer than '.$min.' scenes. '
            .'If they ask for more than '.$max.' scenes, cap at '.$max.'. '
            .'Each item: id (string, unique), type ("slide"|"quiz"), title (string), order (int starting 0), '
            .'objective (string, learning goal for that scene), notes (string, optional teacher notes). '
            .'For type "slide" ONLY, also layoutHint (string — exactly one of: '.$hints.'). Omit layoutHint for type "quiz". '
            .'Order must be contiguous. Use language: '.$language.'. '

            ."\n\n## layoutHint (required on every slide item)\n"
            .'Choose the layout that fits THIS scene. Do NOT use image_card for every slide. Vary by content. '

            ."\n\n"
            .'"image_card" — diagram genuinely helps: processes, systems, cycles, structures. Costs an image API call — use sparingly. '

            ."\n\n"
            .'"three_cards" — exactly three parallel items (stages, types, definitions). No image — cards are the visual. '

            ."\n\n"
            .'"text_panels" — transition, agenda, summary, single key definition, or quote. Large card panel, no image. '

            ."\n\n"
            .'"image_stacked" — one strong diagram plus 2–3 sub-topics each with its own card (stacked). '

            ."\n\n"
            .'"text_bullets" — facts, rules, steps, vocabulary with no natural visual. Card with bullets, no image. '

            ."\n\n"
            .'BUDGET: across the full lesson, at most ~40% of slides should be image_card or image_stacked. '
            .'Example for 6 slides: ~2 image-style, ~2 three_cards, ~1 text_panels, ~1 text_bullets. '
            .'Never make every slide image_card.';
    }

    private function buildOutlineLengthUserSuffix(): string
    {
        ['min' => $min, 'max' => $max] = $this->outlineSceneBounds();

        return "\n\nOUTLINE LENGTH (required): produce between {$min} and {$max} outline items (inclusive). "
            .'If your instructions above mention a maximum (e.g. max 25 slides), treat that as an upper bound within '.$min.'–'.$max.'.';
    }

    /**
     * Models often return too few scenes; prompts alone are unreliable. Regenerate once or twice with an explicit fix.
     *
     * @param  list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>  $outline
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>
     */
    private function ensureOutlineMeetsMinSceneCount(
        array $outline,
        string $baseUrl,
        string $apiKey,
        string $systemOutline,
        string $userOutline,
        float $temperature,
        string $requirement,
        string $lessonName,
    ): array {
        ['min' => $min, 'max' => $max] = $this->outlineSceneBounds();
        if ($min <= 1) {
            return $outline;
        }

        $current = $outline;
        for ($attempt = 0; $attempt < 2 && count($current) < $min; $attempt++) {
            $n = count($current);
            $boost = $this->outlineMaxCompletionTokens();
            $retryTokens = min(32768, (int) round($boost * (1.6 + ($attempt * 0.35))));
            $hints = implode('|', self::LAYOUT_HINTS);
            $retryUser = $userOutline."\n\n"
                .'REGENERATION REQUIRED: Your outline had only '.$n.' item(s). '
                ."Return a NEW complete JSON object with key \"outline\" containing at least {$min} and at most {$max} items. "
                .'Each item: id, type ("slide" or "quiz"), title, order (0 through n-1), objective, optional notes; '
                .'for every type "slide" include layoutHint (one of: '.$hints.'). '
                .'Subdivide the lesson into more scenes with distinct objectives until you reach at least '.$min.' items.';

            try {
                $decoded = $this->chatJson($baseUrl, $apiKey, 'outline', $systemOutline, $retryUser, max($temperature, 0.42), $retryTokens);
                $next = $this->finishOutlineFromLlmJson($decoded, $requirement, $lessonName);
            } catch (Throwable $e) {
                Log::warning('lesson_generation.outline_min_retry_failed', [
                    'attempt' => $attempt,
                    'had_count' => $n,
                    'min' => $min,
                    'message' => $e->getMessage(),
                ]);
                break;
            }

            if (count($next) >= $min) {
                return $next;
            }
            $current = $next;
        }

        if (count($current) < $min) {
            Log::warning('lesson_generation.outline_below_configured_min', [
                'count' => count($current),
                'min' => $min,
                'max' => $max,
            ]);
        }

        return $current;
    }

    /**
     * Last resort when regenerate calls still return too few items: ask only for additional scenes and merge.
     *
     * @param  list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>  $outline
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>
     */
    private function extendOutlineToConfiguredMinimum(
        array $outline,
        string $baseUrl,
        string $apiKey,
        string $requirement,
        string $lessonName,
    ): array {
        ['min' => $min, 'max' => $max] = $this->outlineSceneBounds();
        if ($min <= 1) {
            return $outline;
        }

        $current = $outline;
        for ($round = 0; $round < 4 && count($current) < $min; $round++) {
            $before = count($current);
            $current = $this->appendOutlineItemsViaLlm($current, $baseUrl, $apiKey, $requirement, $lessonName);
            if (count($current) <= $before) {
                break;
            }
        }

        if (count($current) < $min) {
            Log::warning('lesson_generation.outline_extend_failed', [
                'count' => count($current),
                'min' => $min,
                'max' => $max,
            ]);
        }

        return $current;
    }

    /**
     * @param  list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>  $existing
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>
     */
    private function appendOutlineItemsViaLlm(
        array $existing,
        string $baseUrl,
        string $apiKey,
        string $requirement,
        string $lessonName,
    ): array {
        ['min' => $min, 'max' => $max] = $this->outlineSceneBounds();
        $n = count($existing);
        if ($n >= $min || $n >= $max) {
            return $existing;
        }

        $need = min($min - $n, $max - $n);
        if ($need < 1) {
            return $existing;
        }

        $lines = [];
        foreach ($existing as $i => $row) {
            $t = isset($row['title']) && is_string($row['title']) ? trim($row['title']) : 'Scene';
            $lines[] = ($i + 1).'. '.$t;
        }
        $block = implode("\n", $lines);

        $hints = implode('|', self::LAYOUT_HINTS);
        $system = 'You are a curriculum designer. Output a single JSON object only, no markdown fences, with key: '
            .'outline (array). The array must contain ONLY NEW scenes to add after the existing plan—do not repeat '
            .'the same titles or restate existing objectives. '
            .'Each item: id (string, unique), type ("slide"|"quiz"), title, order (int, only for ordering within this new batch, starting 0), '
            .'objective (string), notes (optional string); for type "slide" include layoutHint (one of: '.$hints.'). '
            .'You MUST return at least '.$need.' items in outline and at most '.$need.' items (exactly '.$need.').';

        $ctx = mb_substr(trim($lessonName."\n\n".$requirement), 0, 6000);
        $user = "Already planned scenes (titles):\n{$block}\n\nLesson / requirement:\n{$ctx}\n\n"
            ."Add exactly {$need} additional outline items that continue the lesson (later topics, practice, review, or a quiz)—not duplicates of the list above.";

        $tokens = min(32768, (int) round($this->outlineMaxCompletionTokens() * 1.25));

        try {
            $decoded = $this->chatJson($baseUrl, $apiKey, 'outline', $system, $user, 0.38, $tokens);
        } catch (Throwable $e) {
            Log::warning('lesson_generation.outline_append_failed', [
                'message' => $e->getMessage(),
                'need' => $need,
            ]);

            return $existing;
        }

        $rows = is_array($decoded['outline'] ?? null) ? $decoded['outline'] : [];
        $add = $this->normalizeOutline($rows);
        $room = max(0, $max - count($existing));
        $take = min($need, $room, count($add));
        $add = $take > 0 ? array_slice($add, 0, $take) : [];

        return $this->mergeAppendedOutlineItems($existing, $add, $max);
    }

    /**
     * @param  list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>  $base
     * @param  list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>  $additional
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>
     */
    private function mergeAppendedOutlineItems(array $base, array $additional, int $maxScenes): array
    {
        $merged = $base;
        foreach ($additional as $row) {
            if (count($merged) >= $maxScenes) {
                break;
            }
            $merged[] = $row;
        }

        $out = [];
        foreach (array_values($merged) as $i => $row) {
            $row['order'] = $i;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>
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
            $hintRaw = isset($row['layoutHint']) && is_string($row['layoutHint']) ? trim($row['layoutHint']) : '';
            $layoutHint = in_array($hintRaw, self::LAYOUT_HINTS, true) ? $hintRaw : 'image_card';
            $item = [
                'id' => $id,
                'type' => $type,
                'title' => $title,
                'order' => $order,
                'objective' => $objective,
                'notes' => $notes,
            ];
            if ($type === 'slide') {
                $item['layoutHint'] = $layoutHint;
            }
            $out[] = $item;
        }
        usort($out, fn ($a, $b) => $a['order'] <=> $b['order']);
        $maxScenes = $this->outlineSceneBounds()['max'];
        if (count($out) > $maxScenes) {
            $out = array_slice($out, 0, $maxScenes);
        }
        $reindexed = [];
        foreach (array_values($out) as $idx => $item) {
            $item['order'] = $idx;
            $reindexed[] = $item;
        }

        return $reindexed;
    }

    /**
     * @return list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>
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
                'layoutHint' => 'text_panels',
            ],
            [
                'id' => (string) Str::ulid(),
                'type' => 'slide',
                'title' => 'Core concepts',
                'order' => 1,
                'objective' => 'Teach the main ideas with examples.',
                'notes' => '',
                'layoutHint' => 'image_card',
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
     * @param  list<array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}>  $outline
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
     * @param  array{id: string, type: string, title: string, order: int, objective: string, notes: string, layoutHint?: string}  $spec
     * @return array<string, mixed>
     */
    private function skeletonSceneFromSpec(array $spec): array
    {
        if ($spec['type'] === 'quiz') {
            return [
                'content' => [
                    'type' => 'quiz',
                    'questions' => [[
                        'id' => (string) Str::ulid(),
                        'type' => 'single',
                        'prompt' => 'What is the key takeaway from '.$spec['title'].'?',
                        'points' => 1,
                        'options' => [
                            ['id' => 'a', 'label' => 'A key concept from this lesson'],
                            ['id' => 'b', 'label' => 'Something unrelated'],
                        ],
                        'correctIds' => ['a'],
                        'gradingHint' => '',
                    ]],
                ],
            ];
        }

        return [
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => $spec['title'],
                    'subtitle' => $spec['objective'] !== '' ? $spec['objective'] : '',
                    'footer' => '',
                    'width' => 1000,
                    'height' => 562.5,
                    'elements' => [
                        [
                            'type' => 'image',
                            'id' => (string) Str::ulid(),
                            'x' => 40,
                            'y' => 165,
                            'width' => 450,
                            'height' => 340,
                            'src' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ac/No_image_available.svg/480px-No_image_available.svg.png',
                            'alt' => $spec['title'],
                        ],
                        [
                            'type' => 'card',
                            'id' => (string) Str::ulid(),
                            'x' => 510,
                            'y' => 165,
                            'width' => 450,
                            'height' => 340,
                            'title' => 'Key ideas',
                            'bullets' => [
                                $spec['objective'] !== '' ? $spec['objective'] : $spec['title'],
                                'Review the material and take notes.',
                                $spec['notes'] !== '' ? $spec['notes'] : 'Connect this to what you already know.',
                            ],
                            'caption' => '',
                            'accent' => 'sky',
                            'icon' => '💡',
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
