<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VideoGenerationJob;
use App\Services\MediaGeneration\GeneratedMediaStorage;
use App\Support\ApiJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function missing_prompt_returns_400(): void
    {
        config(['tutor.video_generation.api_key' => 'mm-test']);

        $response = $this->actingAs(User::factory()->create())->postJson('/api/generate/video', [
            'prompt' => '   ',
            'requiresApiKey' => false,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('errorCode', ApiJson::MISSING_REQUIRED_FIELD);
    }

    #[Test]
    public function missing_api_key_returns_401_when_required(): void
    {
        config(['tutor.video_generation.api_key' => '']);

        $response = $this->actingAs(User::factory()->create())->postJson('/api/generate/video', [
            'prompt' => 'A short clip of waves',
            'requiresApiKey' => true,
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('errorCode', ApiJson::MISSING_API_KEY);
    }

    #[Test]
    public function post_returns_202_with_poll_url_and_job_completes_via_minimax_flow(): void
    {
        config([
            'tutor.video_generation.api_key' => 'mm-test',
            'tutor.video_generation.base_url' => 'https://api.minimax.io',
            'tutor.video_generation.poll_interval_seconds' => 0,
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/v1/video_generation') && $request->method() === 'POST') {
                return Http::response([
                    'task_id' => 'task-123',
                    'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
                ], 200);
            }

            if (str_contains($url, '/v1/query/video_generation')) {
                return Http::response([
                    'status' => 'Success',
                    'file_id' => 999001,
                    'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
                ], 200);
            }

            if (str_contains($url, '/v1/files/retrieve')) {
                return Http::response([
                    'file' => [
                        'file_id' => 999001,
                        'download_url' => 'https://cdn.example.test/video.mp4',
                    ],
                    'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
                ], 200);
            }

            if ($url === 'https://cdn.example.test/video.mp4') {
                return Http::response('fake-mp4-bytes', 200, ['Content-Type' => 'video/mp4']);
            }

            return Http::response('unexpected', 404);
        });

        $mockStorage = Mockery::mock(GeneratedMediaStorage::class);
        $mockStorage->shouldReceive('storeBinary')
            ->once()
            ->with('video', 'mp4', 'fake-mp4-bytes')
            ->andReturn([
                'relativePath' => 'generated/video/2026/01/01/01hz.mp4',
                'url' => 'https://app.test/storage/generated/video/2026/01/01/01hz.mp4',
            ]);
        $this->app->instance(GeneratedMediaStorage::class, $mockStorage);

        $response = $this->actingAs(User::factory()->create())->postJson('/api/generate/video', [
            'prompt' => 'Ocean waves at sunset',
            'requiresApiKey' => false,
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.provider', 'minimax-t2v')
            ->assertJsonPath('result.path', 'generated/video/2026/01/01/01hz.mp4');

        $jobId = $response->json('jobId');
        $this->assertNotEmpty($jobId);

        $this->actingAs(User::factory()->create())->getJson('/api/generate/video/'.$jobId)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.mime', 'video/mp4');
    }

    #[Test]
    public function duplicate_client_job_id_returns_existing_job_without_redispatch(): void
    {
        config([
            'tutor.video_generation.api_key' => 'mm-test',
            'tutor.video_generation.base_url' => 'https://api.minimax.io',
            'tutor.video_generation.poll_interval_seconds' => 0,
        ]);

        $existing = VideoGenerationJob::query()->create([
            'client_job_id' => 'client-a',
            'status' => 'completed',
            'request' => ['prompt' => 'x'],
            'result' => ['provider' => 'minimax-t2v', 'url' => 'https://example.test/v.mp4'],
        ]);

        Http::fake();

        $response = $this->actingAs(User::factory()->create())->postJson('/api/generate/video', [
            'prompt' => 'Ignored when deduped',
            'clientJobId' => 'client-a',
            'requiresApiKey' => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('jobId', $existing->id)
            ->assertJsonPath('result.url', 'https://example.test/v.mp4');

        Http::assertNothingSent();
    }

    #[Test]
    public function show_returns_404_for_unknown_job(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/generate/video/01hz0v0000000000000000000')
            ->assertStatus(404);
    }
}
