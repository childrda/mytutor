<?php

namespace App\Services\MediaGeneration;

use App\Support\ApiJson;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * MiniMax text-to-video: create task, poll, resolve download URL (Phase 4.4).
 *
 * @see https://platform.minimax.io/docs/api-reference/video-generation-t2v
 */
final class MinimaxT2vVideoClient
{
    /**
     * @return array{taskId: string}
     *
     * @throws VideoGenerationException
     */
    public function createTask(
        string $apiKey,
        string $baseUrl,
        string $model,
        string $prompt,
        ?int $duration,
        ?string $resolution,
    ): array {
        $baseUrl = rtrim($baseUrl, '/');
        $url = $baseUrl.'/v1/video_generation';
        $timeout = (float) config('tutor.video_generation.submit_timeout', 60);

        $body = [
            'model' => $model,
            'prompt' => $prompt,
        ];
        if ($duration !== null) {
            $body['duration'] = $duration;
        }
        if ($resolution !== null && $resolution !== '') {
            $body['resolution'] = $resolution;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeout)
                ->connectTimeout(min(30.0, $timeout))
                ->post($url, $body);
        } catch (Throwable $e) {
            throw new VideoGenerationException(
                'Video provider request failed: '.$e->getMessage(),
                ApiJson::UPSTREAM_ERROR,
                502,
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new VideoGenerationException(
                'Video provider returned an invalid response',
                ApiJson::UPSTREAM_ERROR,
                502,
            );
        }

        $this->assertBaseResp($json, $response->status());

        $taskId = data_get($json, 'task_id');
        if (! is_string($taskId) || $taskId === '') {
            throw new VideoGenerationException(
                'Video provider returned no task id',
                ApiJson::GENERATION_FAILED,
                502,
            );
        }

        return ['taskId' => $taskId];
    }

    /**
     * Poll until success or failure.
     *
     * @return array{fileId: string}
     *
     * @throws VideoGenerationException
     */
    public function pollUntilFileReady(
        string $apiKey,
        string $baseUrl,
        string $taskId,
        float $pollIntervalSeconds,
        float $maxWaitSeconds,
    ): array {
        $baseUrl = rtrim($baseUrl, '/');
        $queryUrl = $baseUrl.'/v1/query/video_generation';
        $timeout = (float) config('tutor.video_generation.query_timeout', 30);
        $deadline = microtime(true) + $maxWaitSeconds;

        while (microtime(true) < $deadline) {
            try {
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->timeout($timeout)
                    ->connectTimeout(min(15.0, $timeout))
                    ->get($queryUrl, ['task_id' => $taskId]);
            } catch (Throwable $e) {
                throw new VideoGenerationException(
                    'Video status request failed: '.$e->getMessage(),
                    ApiJson::UPSTREAM_ERROR,
                    502,
                );
            }

            $json = $response->json();
            if (! is_array($json)) {
                throw new VideoGenerationException(
                    'Video provider returned an invalid status response',
                    ApiJson::UPSTREAM_ERROR,
                    502,
                );
            }

            $this->assertBaseResp($json, $response->status());

            $statusRaw = (string) data_get($json, 'status', '');
            $status = strtolower($statusRaw);

            if ($status === 'fail' || $status === 'failed') {
                $msg = (string) data_get($json, 'base_resp.status_msg', 'Video generation failed');
                throw new VideoGenerationException($msg, ApiJson::GENERATION_FAILED, 422);
            }

            if ($status === 'success') {
                $fileId = data_get($json, 'file_id') ?? data_get($json, 'file.file_id');
                if ($fileId === null || $fileId === '') {
                    throw new VideoGenerationException(
                        'Video provider returned no file id',
                        ApiJson::GENERATION_FAILED,
                        502,
                    );
                }

                return ['fileId' => (string) $fileId];
            }

            if ($pollIntervalSeconds > 0) {
                usleep((int) round($pollIntervalSeconds * 1_000_000));
            }
        }

        throw new VideoGenerationException(
            'Video generation timed out while waiting for the provider',
            ApiJson::UPSTREAM_ERROR,
            504,
        );
    }

    /**
     * @throws VideoGenerationException
     */
    public function resolveDownloadUrl(string $apiKey, string $baseUrl, string $fileId): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $url = $baseUrl.'/v1/files/retrieve';
        $timeout = (float) config('tutor.video_generation.retrieve_timeout', 60);

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeout)
                ->connectTimeout(min(15.0, $timeout))
                ->get($url, ['file_id' => $fileId]);
        } catch (Throwable $e) {
            throw new VideoGenerationException(
                'Video file metadata request failed: '.$e->getMessage(),
                ApiJson::UPSTREAM_ERROR,
                502,
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new VideoGenerationException(
                'Video provider returned invalid file metadata',
                ApiJson::UPSTREAM_ERROR,
                502,
            );
        }

        $this->assertBaseResp($json, $response->status());

        $raw = data_get($json, 'file.download_url') ?? data_get($json, 'download_url');
        if (! is_string($raw) || $raw === '') {
            throw new VideoGenerationException(
                'Video provider returned no download URL',
                ApiJson::GENERATION_FAILED,
                502,
            );
        }

        return $this->normalizeDownloadUrl($raw);
    }

    /**
     * @throws VideoGenerationException
     */
    public function downloadVideo(string $downloadUrl): string
    {
        $timeout = (float) config('tutor.video_generation.download_timeout', 300);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(min(30.0, $timeout))
                ->get($downloadUrl);
        } catch (Throwable $e) {
            throw new VideoGenerationException(
                'Video download failed: '.$e->getMessage(),
                ApiJson::UPSTREAM_ERROR,
                502,
            );
        }

        if (! $response->successful()) {
            throw new VideoGenerationException(
                'Video download returned HTTP '.$response->status(),
                ApiJson::UPSTREAM_ERROR,
                502,
            );
        }

        $binary = $response->body();
        if ($binary === '') {
            throw new VideoGenerationException(
                'Downloaded video is empty',
                ApiJson::GENERATION_FAILED,
                502,
            );
        }

        return $binary;
    }

    private function normalizeDownloadUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return 'https://'.ltrim($url, '/');
    }

    /**
     * @param  array<string, mixed>  $json
     *
     * @throws VideoGenerationException
     */
    private function assertBaseResp(array $json, int $httpStatus): void
    {
        $code = (int) data_get($json, 'base_resp.status_code', 0);
        if ($code === 0) {
            return;
        }

        $msg = (string) data_get($json, 'base_resp.status_msg', 'MiniMax request failed');

        if ($code === 1026) {
            throw new VideoGenerationException($msg, ApiJson::CONTENT_SENSITIVE, 422);
        }

        if ($code === 1004 || $code === 2049) {
            throw new VideoGenerationException('Invalid or rejected API key', ApiJson::MISSING_API_KEY, 401);
        }

        if ($httpStatus === 401) {
            throw new VideoGenerationException('Invalid or rejected API key', ApiJson::MISSING_API_KEY, 401);
        }

        if ($code === 1008) {
            throw new VideoGenerationException($msg, ApiJson::UPSTREAM_ERROR, 402);
        }

        throw new VideoGenerationException($msg, ApiJson::GENERATION_FAILED, 422);
    }
}
