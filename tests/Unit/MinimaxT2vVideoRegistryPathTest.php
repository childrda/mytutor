<?php

namespace Tests\Unit;

use App\Services\MediaGeneration\MinimaxT2vVideoClient;
use App\Services\MediaGeneration\VideoGenerationException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinimaxT2vVideoRegistryPathTest extends TestCase
{
    #[Test]
    public function active_minimax_video_uses_registry_executor_for_submit(): void
    {
        config([
            'tutor.active.video' => 'minimax-video',
            'tutor.video_generation.submit_timeout' => 25.0,
        ]);

        Http::fake([
            'https://api.reg-video.test/v1/video_generation' => Http::response([
                'task_id' => 'from-registry',
                'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            ], 200),
        ]);

        $client = new MinimaxT2vVideoClient;
        $out = $client->createTask(
            'token-x',
            'https://api.reg-video.test',
            'MiniMax-Hailuo-2.3',
            'ocean',
            6,
            '768p',
        );

        $this->assertSame('from-registry', $out['taskId']);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.reg-video.test/v1/video_generation')) {
                return false;
            }
            $d = $request->data();

            return ($d['model'] ?? null) === 'MiniMax-Hailuo-2.3'
                && ($d['prompt'] ?? null) === 'ocean'
                && ($d['duration'] ?? null) === 6
                && ($d['resolution'] ?? null) === '768p'
                && $request->hasHeader('Authorization', 'Bearer token-x');
        });
    }

    #[Test]
    public function without_active_video_uses_legacy_submit_path(): void
    {
        config(['tutor.active.video' => null]);

        Http::fake([
            'https://legacy-mm.test/v1/video_generation' => Http::response([
                'task_id' => 'legacy-id',
                'base_resp' => ['status_code' => 0],
            ], 200),
        ]);

        $client = new MinimaxT2vVideoClient;
        $out = $client->createTask('k', 'https://legacy-mm.test', 'm', 'p', null, null);

        $this->assertSame('legacy-id', $out['taskId']);
    }

    #[Test]
    public function registry_path_maps_registry_401_to_video_exception(): void
    {
        config(['tutor.active.video' => 'minimax-video']);

        Http::fake([
            'https://api.reg-video.test/v1/video_generation' => Http::response(['error' => 'no'], 401),
        ]);

        $this->expectException(VideoGenerationException::class);
        $this->expectExceptionMessage('Invalid or rejected API key');

        (new MinimaxT2vVideoClient)->createTask('bad', 'https://api.reg-video.test', 'm', 'p', null, null);
    }
}
