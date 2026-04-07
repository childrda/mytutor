<?php

namespace App\Services\LessonGeneration;

use App\Jobs\ProcessLessonGenerationJob;
use App\Models\LessonGenerationJob;
use App\Support\LessonGeneration\LessonGenerationPhases;

class LessonGenerationService
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function createJob(array $raw, ?int $userId): LessonGenerationJob
    {
        $requirement = trim((string) ($raw['requirement'] ?? ''));
        if ($requirement === '') {
            throw new \InvalidArgumentException('Missing required field: requirement');
        }

        $requestPayload = [
            'requirement' => $requirement,
            'language' => $raw['language'] ?? 'en',
            'pdfContent' => $raw['pdfContent'] ?? null,
            'pdfPageImages' => self::sanitizePdfPageImages($raw['pdfPageImages'] ?? null),
            'enableWebSearch' => $raw['enableWebSearch'] ?? false,
            'enableImageGeneration' => $raw['enableImageGeneration'] ?? false,
            'enableVideoGeneration' => $raw['enableVideoGeneration'] ?? false,
            'enableTTS' => $raw['enableTTS'] ?? false,
            'agentMode' => $raw['agentMode'] ?? null,
        ];

        $job = LessonGenerationJob::query()->create([
            'user_id' => $userId,
            'status' => 'queued',
            'phase' => LessonGenerationPhases::QUEUED,
            'progress' => 0,
            'request' => $requestPayload,
        ]);

        ProcessLessonGenerationJob::dispatch($job->id);

        return $job;
    }

    /**
     * @return list<string>
     */
    private static function sanitizePdfPageImages(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $max = max(0, min(4, (int) config('tutor.lesson_generation.max_pdf_page_images', 4)));
        $maxLen = max(10_000, (int) config('tutor.lesson_generation.max_pdf_image_data_url_chars', 700_000));
        $out = [];

        foreach ($raw as $item) {
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
}
