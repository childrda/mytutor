<?php

namespace App\Services\PublishedLesson;

use App\Models\PublishedLesson;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PublishedLessonStorage
{
    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $id);
    }

    /**
     * @param  array<string, mixed>  $stage
     * @param  list<array<string, mixed>>  $scenes
     * @return array{id: string, url: string, document: array<string, mixed>}
     */
    public function persist(array $stage, array $scenes, string $publicBaseUrl): array
    {
        $id = isset($stage['id']) && is_string($stage['id']) && $stage['id'] !== ''
            ? $stage['id']
            : (string) Str::ulid();

        $stage['id'] = $id;

        $document = [
            'id' => $id,
            'stage' => $stage,
            'scenes' => $scenes,
            'createdAt' => now()->toIso8601String(),
        ];

        PublishedLesson::query()->updateOrCreate(
            ['id' => $id],
            [
                'document' => $document,
                'published_at' => now(),
            ],
        );

        $url = rtrim($publicBaseUrl, '/').'/lesson/'.$id;

        return ['id' => $id, 'url' => $url, 'document' => $document];
    }

    public function find(string $id): ?array
    {
        $row = PublishedLesson::query()->find($id);

        return $row?->document;
    }

    public function mediaDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('tutor.disk'));
    }

    public function mediaPath(string $lessonId, string $relative): string
    {
        $base = trim(config('tutor.published_media_path'), '/');

        return $base.'/'.$lessonId.'/'.ltrim($relative, '/');
    }
}
