<?php

namespace App\Support;

/**
 * Resolves default chat credentials when {@code config('tutor.default_chat')} was baked empty
 * by {@code php artisan config:cache} but keys still exist in {@code .env} (see {@see RuntimeEnv}).
 */
final class TutorDefaultChatRuntime
{
    public static function apiKey(): string
    {
        $from = trim((string) config('tutor.default_chat.api_key', ''));
        if ($from !== '') {
            return $from;
        }
        $v = trim(RuntimeEnv::get('TUTOR_DEFAULT_LLM_API_KEY'));
        if ($v !== '') {
            return $v;
        }

        return trim(RuntimeEnv::get('OPENAI_API_KEY'));
    }
}
