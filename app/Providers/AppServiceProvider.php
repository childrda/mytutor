<?php

namespace App\Providers;

use App\Services\Ai\ModelRegistry;
use App\Services\Ai\ModelRegistryHttpExecutor;
use App\Services\Ai\ProviderRegistry;
use App\Services\Ai\TutorActiveRegistrySelection;
use App\Services\MediaGeneration\GeneratedMediaStorage;
use App\Services\Settings\ModelsJsonFileStore;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GeneratedMediaStorage::class, fn (): GeneratedMediaStorage => GeneratedMediaStorage::fromConfig());
        $this->app->singleton(TutorActiveRegistrySelection::class, fn (): TutorActiveRegistrySelection => new TutorActiveRegistrySelection);
        $this->app->singleton(ModelsJsonFileStore::class, fn (): ModelsJsonFileStore => new ModelsJsonFileStore);
        // Not a singleton: models.json is edited from Settings / deploys; queue workers are long-lived
        // and must see new rows (e.g. active LLM id) without a manual worker restart.
        $this->app->bind(ModelRegistry::class, function ($app): ModelRegistry {
            $p = config('tutor.models_json_path');

            return new ModelRegistry(
                is_string($p) && $p !== '' ? $p : config_path('models.json'),
                $app->make(ProviderRegistry::class),
            );
        });
        $this->app->singleton(ProviderRegistry::class, fn (): ProviderRegistry => new ProviderRegistry(config_path('providers.json')));
        $this->app->singleton(ModelRegistryHttpExecutor::class, fn (): ModelRegistryHttpExecutor => new ModelRegistryHttpExecutor);
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
