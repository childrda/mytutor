<?php

namespace App\Support;

/**
 * Resolves the default chat API key when {@code config('tutor.default_chat.api_key')} is empty
 * (e.g. after {@code php artisan config:cache} without env baked in).
 *
 * Does **not** use {@see RuntimeEnv} / full {@code Dotenv::load()} here: that merges the entire
 * {@code .env} into the process and can overwrite non-empty vars from PHP-FPM or systemd with
 * empty placeholders from {@code .env}, breaking TTS, lesson generation, etc. on the same worker.
 */
final class TutorDefaultChatRuntime
{
    public static function apiKey(): string
    {
        $from = trim((string) config('tutor.default_chat.api_key', ''));
        if ($from !== '') {
            return $from;
        }

        foreach (['TUTOR_DEFAULT_LLM_API_KEY', 'OPENAI_API_KEY'] as $name) {
            $v = self::processEnvironmentValue($name);
            if ($v !== '') {
                return $v;
            }
        }

        if (function_exists('app') && app()->hasBeenBootstrapped() && app()->configurationIsCached()) {
            foreach (['TUTOR_DEFAULT_LLM_API_KEY', 'OPENAI_API_KEY'] as $name) {
                $v = self::readKeyFromDotenvFile($name);
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return '';
    }

    private static function processEnvironmentValue(string $name): string
    {
        if (isset($_ENV[$name]) && is_string($_ENV[$name])) {
            $t = trim($_ENV[$name]);
            if ($t !== '') {
                return $t;
            }
        }
        if (isset($_SERVER[$name]) && is_string($_SERVER[$name])) {
            $t = trim($_SERVER[$name]);
            if ($t !== '') {
                return $t;
            }
        }
        $g = getenv($name);
        if (is_string($g)) {
            $t = trim($g);
            if ($t !== '') {
                return $t;
            }
        }

        return '';
    }

    /**
     * Parse {@code .env} for a single KEY=value without mutating the process environment.
     */
    private static function readKeyFromDotenvFile(string $key): string
    {
        $path = base_path('.env');
        if (! is_readable($path)) {
            return '';
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return '';
        }
        $prefix = $key.'=';
        foreach (explode("\n", $raw) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (preg_match('/^export\s+/i', $trimmed)) {
                $trimmed = trim(substr($trimmed, 6));
            }
            if (! str_starts_with($trimmed, $prefix)) {
                continue;
            }
            $value = trim(substr($trimmed, strlen($prefix)));
            if ($value === '') {
                continue;
            }
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            return trim($value);
        }

        return '';
    }
}
