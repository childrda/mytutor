<?php

namespace App\Support;

/**
 * Read API keys and other env vars from the project {@code .env} at runtime when Laravel has
 * {@see \Illuminate\Foundation\Application::configurationIsCached()} — in that mode Laravel does not
 * reload {@code .env} on each request and the {@see env()} helper returns {@code null} outside config files.
 *
 * Uses vlucas/phpdotenv {@see \Dotenv\Dotenv::safeLoad()} once per PHP process so {@code .env} stays the
 * source of truth without re-running {@code php artisan config:cache} after every key change.
 */
final class RuntimeEnv
{
    private static bool $dotenvFileMerged = false;

    public static function get(string $name): string
    {
        if (! self::isValidEnvName($name)) {
            return '';
        }

        $v = self::lookup($name);
        if ($v !== '') {
            return $v;
        }

        if (self::shouldMergeDotenvFromFile()) {
            self::mergeDotenvFileOnce();
            $v = self::lookup($name);
        }

        return $v;
    }

    private static function shouldMergeDotenvFromFile(): bool
    {
        if (! function_exists('app')) {
            return false;
        }
        $app = app();
        if (! $app->hasBeenBootstrapped()) {
            return false;
        }
        if (! method_exists($app, 'configurationIsCached')) {
            return false;
        }

        return $app->configurationIsCached();
    }

    private static function mergeDotenvFileOnce(): void
    {
        if (self::$dotenvFileMerged) {
            return;
        }
        self::$dotenvFileMerged = true;

        $path = base_path('.env');
        if (! is_readable($path)) {
            return;
        }
        if (! class_exists(\Dotenv\Dotenv::class)) {
            return;
        }
        try {
            \Dotenv\Dotenv::createImmutable(base_path())->safeLoad();
        } catch (\Throwable) {
            // Malformed .env — leave keys unset
        }
    }

    private static function lookup(string $name): string
    {
        if (isset($_ENV[$name]) && is_string($_ENV[$name]) && $_ENV[$name] !== '') {
            return $_ENV[$name];
        }
        if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return $_SERVER[$name];
        }
        $g = getenv($name);
        if (is_string($g) && $g !== '') {
            return $g;
        }

        return '';
    }

    private static function isValidEnvName(string $name): bool
    {
        return $name !== '' && (bool) preg_match('/\A[A-Z][A-Z0-9_]*\z/', $name);
    }
}
