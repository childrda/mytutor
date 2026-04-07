<?php

namespace App\Providers;

use App\Services\MediaGeneration\GeneratedMediaStorage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GeneratedMediaStorage::class, fn (): GeneratedMediaStorage => GeneratedMediaStorage::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->ensureWritableRuntimeDirectories();
    }

    /**
     * Blade and file sessions need directories writable by the PHP process. When
     * VIEW_COMPILED_PATH / SESSION_FILES_PATH point outside storage/, create them early.
     */
    private function ensureWritableRuntimeDirectories(): void
    {
        $paths = array_filter([
            config('view.compiled'),
            config('session.files'),
        ]);

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            if (is_dir($path)) {
                continue;
            }

            @mkdir($path, 0775, true);
        }
    }
}
