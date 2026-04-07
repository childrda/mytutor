<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ApiJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ParsePdfTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_cannot_parse_pdf(): void
    {
        $this->postJson('/api/parse-pdf', [])->assertUnauthorized();
    }

    #[Test]
    public function missing_file_returns_400(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/parse-pdf', [])
            ->assertStatus(400)
            ->assertJsonPath('errorCode', ApiJson::MISSING_REQUIRED_FIELD);
    }

    #[Test]
    public function extracts_text_from_sample_pdf(): void
    {
        $user = User::factory()->create();
        $path = base_path('tests/Fixtures/sample.pdf');
        $this->assertFileExists($path);

        $file = new UploadedFile($path, 'sample.pdf', 'application/pdf', null, true);

        $res = $this->actingAs($user)->post('/api/parse-pdf', ['file' => $file]);
        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pages', 1)
            ->assertJsonPath('meta.truncated', false);
        $this->assertStringContainsString('Hello Phase5 fixture', (string) $res->json('text'));
    }

    #[Test]
    public function rejects_oversized_pdf(): void
    {
        $user = User::factory()->create();
        Config::set('tutor.pdf_parse.max_file_bytes', 100);

        $path = base_path('tests/Fixtures/sample.pdf');
        $file = new UploadedFile($path, 'sample.pdf', 'application/pdf', null, true);

        $this->actingAs($user)->post('/api/parse-pdf', ['file' => $file])
            ->assertStatus(413)
            ->assertJsonPath('errorCode', ApiJson::INVALID_REQUEST);
    }
}
