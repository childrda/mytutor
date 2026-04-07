<?php

namespace App\Services\LessonGeneration;

use App\Jobs\ProcessLessonGenerationJob;
use App\Models\LessonGenerationJob;

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
            'enableWebSearch' => $raw['enableWebSearch'] ?? false,
            'enableImageGeneration' => $raw['enableImageGeneration'] ?? false,
            'enableVideoGeneration' => $raw['enableVideoGeneration'] ?? false,
            'enableTTS' => $raw['enableTTS'] ?? false,
            'agentMode' => $raw['agentMode'] ?? null,
        ];

        $job = LessonGenerationJob::query()->create([
            'user_id' => $userId,
            'status' => 'queued',
            'request' => $requestPayload,
        ]);

        ProcessLessonGenerationJob::dispatch($job->id);

        return $job;
    }
}
