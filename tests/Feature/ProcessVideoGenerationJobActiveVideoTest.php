<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideoGenerationJob;
use App\Models\VideoGenerationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessVideoGenerationJobActiveVideoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function job_fails_fast_when_active_video_is_not_minimax_compatible(): void
    {
        config([
            'tutor.active.video' => 'seedance',
            'tutor.video_generation.api_key' => 'mm-test',
        ]);

        $job = VideoGenerationJob::query()->create([
            'user_id' => null,
            'status' => 'queued',
            'request' => ['prompt' => 'A short clip'],
        ]);

        ProcessVideoGenerationJob::dispatchSync($job->id, null);

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('minimax-video', (string) $job->error);
    }
}
