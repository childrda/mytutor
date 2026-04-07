<?php

namespace App\Services\MediaGeneration;

use App\Jobs\ProcessVideoGenerationJob;
use App\Models\VideoGenerationJob;

class VideoGenerationService
{
    /**
     * @param  array<string, mixed>  $body
     * @return array{0: VideoGenerationJob, 1: bool}
     */
    public function createJob(array $body, ?int $userId, ?string $overrideApiKey): array
    {
        $prompt = isset($body['prompt']) && is_string($body['prompt']) ? trim($body['prompt']) : '';
        $maxChars = max(100, (int) config('tutor.video_generation.max_prompt_chars', 2000));
        if ($prompt === '') {
            throw new \InvalidArgumentException('Prompt is required');
        }
        if (strlen($prompt) > $maxChars) {
            throw new \InvalidArgumentException('Prompt exceeds maximum length ('.$maxChars.' characters)');
        }

        $clientJobId = isset($body['clientJobId']) && is_string($body['clientJobId'])
            ? trim($body['clientJobId'])
            : '';
        if (strlen($clientJobId) > 128) {
            throw new \InvalidArgumentException('clientJobId must be at most 128 characters');
        }
        if ($clientJobId !== '' && ! preg_match('/^[a-zA-Z0-9._-]+$/', $clientJobId)) {
            throw new \InvalidArgumentException('clientJobId may only contain letters, digits, dot, underscore, and hyphen');
        }

        if ($clientJobId !== '') {
            $existing = VideoGenerationJob::query()
                ->where('client_job_id', $clientJobId)
                ->where('status', '!=', 'failed')
                ->orderByDesc('created_at')
                ->first();
            if ($existing !== null) {
                return [$existing, false];
            }
        }

        $model = isset($body['model']) && is_string($body['model']) ? trim($body['model']) : null;
        $duration = null;
        if (isset($body['duration']) && is_numeric($body['duration'])) {
            $duration = (int) $body['duration'];
        }
        $resolution = isset($body['resolution']) && is_string($body['resolution'])
            ? trim($body['resolution'])
            : null;

        $job = VideoGenerationJob::query()->create([
            'user_id' => $userId,
            'client_job_id' => $clientJobId !== '' ? $clientJobId : null,
            'status' => 'queued',
            'request' => [
                'prompt' => $prompt,
                'model' => $model !== '' ? $model : null,
                'duration' => $duration,
                'resolution' => $resolution !== '' ? $resolution : null,
            ],
        ]);

        ProcessVideoGenerationJob::dispatch($job->id, $overrideApiKey);
        $job->refresh();

        return [$job, true];
    }
}
