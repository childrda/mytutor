<?php

namespace App\Services\LessonGeneration;

use App\Models\LessonGenerationJob;
use App\Models\TutorLesson;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LessonImportService
{
    /**
     * Persist a completed generation job as a TutorLesson with scenes.
     *
     * @return array{lesson: TutorLesson}
     */
    public function importFromJob(User $user, LessonGenerationJob $job): array
    {
        if ($job->status !== 'completed') {
            throw new InvalidArgumentException('Job is not completed');
        }

        if ($job->user_id !== null && $job->user_id !== $user->id) {
            throw new InvalidArgumentException('This generation job belongs to another account');
        }

        $result = $job->result;
        if (! is_array($result)) {
            throw new InvalidArgumentException('Job has no result payload');
        }

        $stage = is_array($result['stage'] ?? null) ? $result['stage'] : [];
        $scenes = is_array($result['scenes'] ?? null) ? $result['scenes'] : [];

        return DB::transaction(function () use ($user, $stage, $scenes): array {
            $lesson = TutorLesson::query()->create([
                'user_id' => $user->id,
                'name' => is_string($stage['name'] ?? null) && $stage['name'] !== ''
                    ? $stage['name']
                    : 'Generated lesson',
                'description' => is_string($stage['description'] ?? null) ? $stage['description'] : null,
                'language' => is_string($stage['language'] ?? null) ? $stage['language'] : null,
                'style' => is_string($stage['style'] ?? null) ? $stage['style'] : null,
                'current_scene_id' => null,
                'agent_ids' => null,
                'meta' => [
                    'importedFrom' => 'lesson_generation_job',
                ],
            ]);

            foreach ($scenes as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $type = $row['type'] ?? 'slide';
                if (! in_array($type, ['slide', 'quiz', 'interactive', 'pbl'], true)) {
                    $type = 'slide';
                }

                $title = is_string($row['title'] ?? null) && $row['title'] !== ''
                    ? $row['title']
                    : 'Scene '.($index + 1);

                $order = isset($row['order']) && is_numeric($row['order']) ? (int) $row['order'] : $index;

                $content = is_array($row['content'] ?? null) ? $row['content'] : ['type' => $type];

                $whiteboard = $row['whiteboards'] ?? $row['whiteboard'] ?? null;
                if ($whiteboard !== null && ! is_array($whiteboard)) {
                    $whiteboard = null;
                }

                $multi = $row['multiAgent'] ?? $row['multi_agent'] ?? null;
                if ($multi !== null && ! is_array($multi)) {
                    $multi = null;
                }

                $actions = $row['actions'] ?? null;
                if ($actions !== null && ! is_array($actions)) {
                    $actions = null;
                }

                $lesson->scenes()->create([
                    'type' => $type,
                    'title' => $title,
                    'scene_order' => $order,
                    'content' => $content,
                    'actions' => $actions,
                    'whiteboard' => $whiteboard,
                    'multi_agent' => $multi,
                ]);
            }

            $first = $lesson->scenes()->orderBy('scene_order')->first();
            if ($first) {
                $lesson->update(['current_scene_id' => $first->id]);
            }

            return ['lesson' => $lesson->fresh(['scenes'])];
        });
    }
}
