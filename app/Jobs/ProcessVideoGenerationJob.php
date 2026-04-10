<?php

namespace App\Jobs;

use App\Models\VideoGenerationJob;
use App\Services\Ai\ModelRegistry;
use App\Services\MediaGeneration\GeneratedMediaStorage;
use App\Services\MediaGeneration\MinimaxT2vVideoClient;
use App\Services\MediaGeneration\VideoGenerationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessVideoGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(
        public string $jobUlid,
        public ?string $overrideApiKey = null,
    ) {}

    public function handle(
        MinimaxT2vVideoClient $minimax,
        GeneratedMediaStorage $mediaStorage,
    ): void {
        $record = VideoGenerationJob::query()->find($this->jobUlid);
        if (! $record) {
            return;
        }

        $record->update(['status' => 'running']);

        $req = $record->request;
        $prompt = is_array($req) ? (string) ($req['prompt'] ?? '') : '';
        $modelOverride = is_array($req) && isset($req['model']) && is_string($req['model']) && $req['model'] !== ''
            ? trim($req['model'])
            : null;
        $duration = is_array($req) && isset($req['duration']) && is_numeric($req['duration'])
            ? (int) $req['duration']
            : null;
        $resolution = is_array($req) && isset($req['resolution']) && is_string($req['resolution']) && $req['resolution'] !== ''
            ? trim($req['resolution'])
            : null;

        $apiKey = $this->overrideApiKey !== null && $this->overrideApiKey !== ''
            ? $this->overrideApiKey
            : (string) config('tutor.video_generation.api_key');
        if ($apiKey === '') {
            $record->update([
                'status' => 'failed',
                'error' => 'API key is required for server-side video generation.',
            ]);

            return;
        }

        $baseUrl = rtrim((string) config('tutor.video_generation.base_url'), '/');
        $model = $modelOverride ?? (string) config('tutor.video_generation.model', 'MiniMax-Hailuo-2.3');

        $registry = app(ModelRegistry::class);
        if ($registry->hasActive('video') && $registry->activeKey('video') !== 'minimax-video') {
            $record->update([
                'status' => 'failed',
                'error' => 'Server video generation uses the MiniMax T2V client only. '
                    .'Set TUTOR_ACTIVE_VIDEO=minimax-video (or save it in Settings when env is unset), or clear the active video key. '
                    .'Current active key: '.($registry->activeKey('video') ?? '').'.',
            ]);

            return;
        }

        try {
            $task = $minimax->createTask($apiKey, $baseUrl, $model, $prompt, $duration, $resolution);
            $pollInterval = (float) config('tutor.video_generation.poll_interval_seconds', 5);
            $maxWait = (float) config('tutor.video_generation.poll_max_seconds', 600);
            $file = $minimax->pollUntilFileReady($apiKey, $baseUrl, $task['taskId'], $pollInterval, $maxWait);
            $downloadUrl = $minimax->resolveDownloadUrl($apiKey, $baseUrl, $file['fileId']);
            $binary = $minimax->downloadVideo($downloadUrl);
            $stored = $mediaStorage->storeBinary('video', 'mp4', $binary);
        } catch (VideoGenerationException $e) {
            $record->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            return;
        } catch (Throwable $e) {
            report($e);
            $record->update([
                'status' => 'failed',
                'error' => 'Unexpected error during video generation',
            ]);

            return;
        }

        $record->update([
            'status' => 'completed',
            'result' => [
                'provider' => 'minimax-t2v',
                'url' => $stored['url'],
                'path' => $stored['relativePath'],
                'mime' => 'video/mp4',
                'taskId' => $task['taskId'],
            ],
        ]);
    }
}
