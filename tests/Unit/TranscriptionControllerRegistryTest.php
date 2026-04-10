<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\TranscriptionController;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TranscriptionControllerRegistryTest extends TestCase
{
    #[Test]
    public function active_asr_uses_registry_multipart_request(): void
    {
        config([
            'tutor.active.asr' => 'openai-whisper',
            'tutor.default_chat.api_key' => 'sk-test',
            'tutor.default_chat.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'recognized'], 200),
        ]);

        $file = UploadedFile::fake()->createWithContent('note.mp3', 'fake-audio-payload');
        $request = Request::create('/api/transcription', 'POST', [], [], ['file' => $file]);

        $response = app(TranscriptionController::class)($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertTrue($data['success'] ?? false);
        $this->assertSame('recognized', $data['text'] ?? null);

        Http::assertSent(function ($req) {
            $body = (string) $req->body();

            return str_contains($req->url(), 'audio/transcriptions')
                && str_contains($body, 'whisper-1')
                && str_contains($body, 'name="file"');
        });
    }

    #[Test]
    public function stub_asr_returns_400_without_calling_upstream(): void
    {
        config([
            'tutor.active.asr' => 'qwen-asr',
            'tutor.default_chat.api_key' => 'sk-test',
            'tutor.default_chat.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::fake();

        $file = UploadedFile::fake()->createWithContent('note.mp3', 'x');
        $request = Request::create('/api/transcription', 'POST', [], [], ['file' => $file]);

        $response = app(TranscriptionController::class)($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['success'] ?? true);
        $this->assertStringContainsString('request_format (stub)', (string) ($data['error'] ?? ''));

        Http::assertNothingSent();
    }
}
