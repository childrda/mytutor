<?php

namespace Tests\Unit;

use App\Http\Resources\TutorSceneResource;
use App\Models\TutorScene;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TutorSceneResourceTest extends TestCase
{
    #[Test]
    public function it_rewrites_absolute_app_storage_urls_to_root_relative_paths(): void
    {
        $scene = new TutorScene([
            'tutor_lesson_id' => '01knmx00000000000000000000',
            'type' => 'slide',
            'title' => 'Test',
            'scene_order' => 0,
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'T',
                    'elements' => [
                        [
                            'type' => 'image',
                            'id' => 'img1',
                            'src' => 'http://localhost:8080/storage/generated/image/2026/04/07/01abc.png',
                            'x' => 40,
                            'y' => 100,
                            'width' => 400,
                            'height' => 300,
                            'alt' => '',
                        ],
                    ],
                ],
            ],
        ]);
        $scene->id = '01knmx11111111111111111111';

        $data = (new TutorSceneResource($scene))->toArray(Request::create('/'));

        $src = $data['content']['canvas']['elements'][0]['src'];
        $this->assertSame('/storage/generated/image/2026/04/07/01abc.png', $src);
    }

    #[Test]
    public function it_leaves_external_https_urls_unchanged(): void
    {
        $url = 'https://upload.wikimedia.org/wikipedia/commons/thumb/1/1/a.png/100px-a.png';
        $scene = new TutorScene([
            'tutor_lesson_id' => '01knmx00000000000000000000',
            'type' => 'slide',
            'title' => 'Test',
            'scene_order' => 0,
            'content' => [
                'type' => 'slide',
                'canvas' => [
                    'title' => 'T',
                    'elements' => [
                        ['type' => 'image', 'id' => 'i', 'src' => $url, 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'alt' => ''],
                    ],
                ],
            ],
        ]);
        $scene->id = '01knmx22222222222222222222';

        $data = (new TutorSceneResource($scene))->toArray(Request::create('/'));

        $this->assertSame($url, $data['content']['canvas']['elements'][0]['src']);
    }
}
