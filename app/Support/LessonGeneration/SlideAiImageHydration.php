<?php

namespace App\Support\LessonGeneration;

use App\Services\MediaGeneration\GeneratedMediaStorage;
use App\Services\MediaGeneration\ImageGenerationException;
use App\Services\MediaGeneration\OpenAiImageGenerator;
use App\Support\ApiJson;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When lesson generation requests AI images (no PDF), replaces placeholder image src values
 * with URLs to server-stored images from {@see OpenAiImageGenerator}.
 */
final class SlideAiImageHydration
{
    /**
     * @param  list<array<string, mixed>>  $scenes
     * @return array{scenes: list<array<string, mixed>>, failures: list<array{step: string, message: string, code: string, slideTitle?: string}>}
     */
    public static function hydrateScenes(array $scenes, string $requirement, string $language): array
    {
        if ((string) config('tutor.image_generation.api_key') === '') {
            return ['scenes' => $scenes, 'failures' => []];
        }

        $max = max(1, min(32, (int) config('tutor.lesson_generation.ai_slide_images_max', 12)));
        $generator = app(OpenAiImageGenerator::class);
        $storage = GeneratedMediaStorage::fromConfig();
        $used = 0;
        $stopAll = false;
        $failures = [];

        foreach ($scenes as $si => $scene) {
            if ($stopAll) {
                break;
            }
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
            $elements = $canvas['elements'] ?? null;
            if (! is_array($elements)) {
                continue;
            }

            $canvasTitle = trim((string) ($canvas['title'] ?? ''));
            $sceneTitle = trim((string) ($scene['title'] ?? ''));

            foreach ($elements as $ei => $el) {
                if ($stopAll) {
                    break;
                }
                if (! is_array($el) || ($el['type'] ?? '') !== 'image') {
                    continue;
                }
                $src = isset($el['src']) && is_string($el['src']) ? trim($el['src']) : '';
                if (! self::srcNeedsGeneration($src)) {
                    continue;
                }
                if ($used >= $max) {
                    $stopAll = true;
                    break;
                }

                $alt = isset($el['alt']) && is_string($el['alt']) ? trim($el['alt']) : '';
                $prompt = self::buildPrompt(
                    $requirement,
                    $language,
                    $canvasTitle !== '' ? $canvasTitle : $sceneTitle,
                    $alt,
                );

                try {
                    $out = $generator->generate($prompt);
                    $stored = $storage->storeBinary('image', 'png', $out['binary']);
                    $elements[$ei]['src'] = $stored['url'];
                    if ($alt === '' && isset($out['revisedPrompt']) && is_string($out['revisedPrompt']) && $out['revisedPrompt'] !== '') {
                        $elements[$ei]['alt'] = mb_substr($out['revisedPrompt'], 0, 240);
                    }
                    $used++;
                } catch (ImageGenerationException $e) {
                    $recovered = false;
                    $lastError = $e;
                    if ($e->errorCode === ApiJson::CONTENT_SENSITIVE) {
                        $slideLabel = $canvasTitle !== '' ? $canvasTitle : $sceneTitle;
                        $retryPrompts = [];
                        $soft = LessonSlideImageModeration::soften($prompt);
                        if ($soft !== '') {
                            $retryPrompts[] = $soft;
                        }
                        $retryPrompts[] = LessonSlideImageModeration::minimalSafePrompt(
                            $slideLabel !== '' ? $slideLabel : 'Lesson slide',
                            $requirement,
                            $language,
                        );
                        foreach ($retryPrompts as $retryPrompt) {
                            try {
                                $out = $generator->generate(mb_substr($retryPrompt, 0, 3800));
                                $stored = $storage->storeBinary('image', 'png', $out['binary']);
                                $elements[$ei]['src'] = $stored['url'];
                                if ($alt === '' && isset($out['revisedPrompt']) && is_string($out['revisedPrompt']) && $out['revisedPrompt'] !== '') {
                                    $elements[$ei]['alt'] = mb_substr($out['revisedPrompt'], 0, 240);
                                }
                                $used++;
                                $recovered = true;
                                Log::info('Lesson slide image generation succeeded after moderation fallback', [
                                    'scene_index' => $si,
                                    'element_index' => $ei,
                                    'slide_title' => $sceneTitle,
                                ]);
                                break;
                            } catch (ImageGenerationException $ex) {
                                $lastError = $ex;
                            }
                        }
                    }
                    if (! $recovered) {
                        Log::warning('Lesson slide image generation failed (provider)', [
                            'message' => $e->getMessage(),
                            'code' => $e->errorCode,
                            'http' => $e->httpStatus,
                            'scene_index' => $si,
                            'element_index' => $ei,
                            'slide_title' => $sceneTitle,
                        ]);
                        $msg = $e->errorCode === ApiJson::CONTENT_SENSITIVE
                            ? $e->getMessage().' Safer and minimal fallbacks were tried; last error: '.$lastError->getMessage()
                            : $e->getMessage();
                        $failures[] = [
                            'step' => 'slide_hydration',
                            'slideTitle' => $sceneTitle !== '' ? $sceneTitle : 'Untitled slide',
                            'message' => $msg,
                            'code' => $lastError->errorCode,
                        ];
                    }
                } catch (Throwable $e) {
                    Log::warning('Lesson slide image generation failed', [
                        'message' => $e->getMessage(),
                        'exception' => $e::class,
                        'scene_index' => $si,
                        'element_index' => $ei,
                        'slide_title' => $sceneTitle,
                    ]);
                    $failures[] = [
                        'step' => 'slide_hydration',
                        'slideTitle' => $sceneTitle !== '' ? $sceneTitle : 'Untitled slide',
                        'message' => $e->getMessage(),
                        'code' => 'UNEXPECTED',
                    ];
                }
            }

            $canvas['elements'] = $elements;
            $content['canvas'] = $canvas;
            $scene['content'] = $content;
            $scenes[$si] = $scene;
        }

        return ['scenes' => $scenes, 'failures' => $failures];
    }

    public static function srcNeedsGeneration(string $src): bool
    {
        if ($src === '') {
            return true;
        }
        // Unresolved gen_img_* after resolveGenImgPlaceholders (e.g. provider policy rejection): retry here.
        if (preg_match('/^gen_img_\d+$/i', $src) === 1) {
            return true;
        }
        if (preg_match('/^ai_generate:/i', $src) === 1) {
            return true;
        }
        if (str_starts_with($src, 'data:image')) {
            return false;
        }
        if (preg_match('/^pdf_page:\d+$/i', $src) === 1) {
            return true;
        }
        if (preg_match('#^https?://#i', $src) === 1) {
            $low = strtolower($src);
            if (str_contains($low, 'no_image_available')) {
                return true;
            }
            $host = parse_url($src, PHP_URL_HOST);
            $host = is_string($host) ? strtolower($host) : '';
            if ($host !== '' && (str_contains($host, 'example.com') || str_contains($host, 'placeholder'))) {
                return true;
            }

            return false;
        }

        return true;
    }

    private static function buildPrompt(string $requirement, string $language, string $slideTitle, string $alt): string
    {
        $parts = ['Educational illustration for a classroom slide.', 'Topic: '.($slideTitle !== '' ? $slideTitle : 'lesson slide').'.'];
        if ($alt !== '') {
            $parts[] = 'Visual brief: '.$alt;
        }
        $req = trim(mb_substr($requirement, 0, 600));
        if ($req !== '') {
            $parts[] = 'Course context: '.$req;
        }
        $parts[] = 'Style: clean, accurate, age-appropriate for students; prefer a clear diagram or infographic with short English labels where helpful.';
        if ($language !== '' && strtolower($language) !== 'en') {
            $parts[] = 'If any words appear in the image, bias toward '.$language.' for short labels when it fits the topic.';
        }

        return implode(' ', $parts);
    }
}
