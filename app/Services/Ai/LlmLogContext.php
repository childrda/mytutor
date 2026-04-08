<?php

namespace App\Services\Ai;

/**
 * Request-scoped defaults for LLM exchange logging (user id, source label, lesson job id).
 * Used by queue jobs where Auth has no user; push in run(), pop in finally.
 */
final class LlmLogContext
{
    /** @var list<array{user_id?: int|null, source?: string, lesson_generation_job_id?: string|null}> */
    private static array $stack = [];

    /**
     * @param  array{user_id?: int|null, source?: string, lesson_generation_job_id?: string|null}  $frame
     */
    public static function push(array $frame): void
    {
        self::$stack[] = $frame;
    }

    public static function pop(): void
    {
        array_pop(self::$stack);
    }

    /**
     * @return array{user_id?: int|null, source?: string, lesson_generation_job_id?: string|null}
     */
    public static function current(): array
    {
        if (self::$stack === []) {
            return [];
        }

        return self::$stack[array_key_last(self::$stack)];
    }
}
