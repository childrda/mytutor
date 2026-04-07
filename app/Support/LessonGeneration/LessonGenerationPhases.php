<?php

namespace App\Support\LessonGeneration;

/**
 * Server-driven generation stages (OpenMaic-style pipeline). Values are stable API contracts.
 */
final class LessonGenerationPhases
{
    public const QUEUED = 'queued';

    public const CLASSROOM_ROLES = 'classroom_roles';

    public const COURSE_OUTLINE = 'course_outline';

    public const PAGE_CONTENT = 'page_content';

    public const TEACHING_ACTIONS = 'teaching_actions';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /**
     * Ordered UI pipeline (subset of phases shown as the main timeline).
     *
     * @return list<array{phase: string, title: string, caption: string}>
     */
    public static function pipelineSteps(): array
    {
        return [
            [
                'phase' => self::CLASSROOM_ROLES,
                'title' => 'Classroom roles',
                'caption' => 'Personas and teaching context for your lesson.',
            ],
            [
                'phase' => self::COURSE_OUTLINE,
                'title' => 'Course outline',
                'caption' => 'Structuring topics and flow.',
            ],
            [
                'phase' => self::PAGE_CONTENT,
                'title' => 'Page content',
                'caption' => 'Slides and quizzes on the canvas.',
            ],
            [
                'phase' => self::TEACHING_ACTIONS,
                'title' => 'Teaching actions',
                'caption' => 'Narration, spotlight, and interaction hooks.',
            ],
        ];
    }

    /**
     * Monotonic order for comparing progress within the pipeline (excludes terminal states).
     *
     * @return list<string>
     */
    public static function orderedPhases(): array
    {
        return [
            self::QUEUED,
            self::CLASSROOM_ROLES,
            self::COURSE_OUTLINE,
            self::PAGE_CONTENT,
            self::TEACHING_ACTIONS,
            self::COMPLETED,
        ];
    }

    public static function rank(string $phase): int
    {
        $i = array_search($phase, self::orderedPhases(), true);

        return $i === false ? -1 : $i;
    }
}
