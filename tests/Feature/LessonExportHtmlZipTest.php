<?php

namespace Tests\Feature;

use App\Models\TutorLesson;
use App\Models\TutorScene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

class LessonExportHtmlZipTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_cannot_export(): void
    {
        $lesson = TutorLesson::factory()->create();

        $this->get("/tutor-api/lessons/{$lesson->id}/export/html-zip")
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function owner_downloads_zip_with_index_html(): void
    {
        $user = User::factory()->create();
        $lesson = TutorLesson::factory()->for($user)->create([
            'name' => 'Export Demo Lesson',
            'description' => 'A short description',
        ]);
        TutorScene::query()->create([
            'tutor_lesson_id' => $lesson->id,
            'type' => 'slide',
            'title' => 'First scene',
            'scene_order' => 0,
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'Canvas headline',
                    'elements' => [
                        ['type' => 'text', 'text' => 'Body line'],
                    ],
                ],
            ],
            'actions' => null,
            'whiteboard' => null,
            'multi_agent' => null,
        ]);

        $response = $this->actingAs($user)->get("/tutor-api/lessons/{$lesson->id}/export/html-zip");

        $response->assertOk();
        $this->assertStringContainsString('zip', strtolower((string) $response->headers->get('Content-Type')));

        $base = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $base);
        $zipPath = $base->getFile()->getPathname();
        $this->assertFileExists($zipPath);

        $tmp = tempnam(sys_get_temp_dir(), 'mtexp');
        $this->assertNotFalse($tmp);
        copy($zipPath, $tmp);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $this->assertNotFalse($zip->locateName('index.html'));
        $this->assertNotFalse($zip->locateName('styles.css'));
        $this->assertNotFalse($zip->locateName('manifest.json'));

        $html = (string) $zip->getFromName('index.html');
        $this->assertStringContainsString('Export Demo Lesson', $html);
        $this->assertStringContainsString('Canvas headline', $html);
        $this->assertStringContainsString('Body line', $html);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertIsArray($manifest);
        $this->assertSame($lesson->id, $manifest['lessonId'] ?? null);

        $zip->close();
        @unlink($tmp);
    }

    #[Test]
    public function other_user_gets_forbidden(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $lesson = TutorLesson::factory()->for($owner)->create();

        $this->actingAs($other)
            ->get("/tutor-api/lessons/{$lesson->id}/export/html-zip")
            ->assertForbidden();
    }
}
