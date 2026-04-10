<?php

namespace App\Services\Settings;

use App\Services\Ai\ModelRegistry;
use App\Services\Ai\ModelRegistryException;
use App\Services\Ai\ProviderRegistry;
use JsonException;

/**
 * Read/write {@see config/models.json} (array-per-capability source shape). Validates by loading {@see ModelRegistry}.
 */
final class ModelsJsonFileStore
{
    public function __construct(
        private readonly ?string $path = null,
    ) {}

    public function path(): string
    {
        $p = $this->path;
        if (is_string($p) && $p !== '') {
            return $p;
        }
        $cfg = config('tutor.models_json_path');

        return is_string($cfg) && $cfg !== '' ? $cfg : config_path('models.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function readRaw(): array
    {
        $path = $this->path();
        if (! is_readable($path)) {
            throw ModelRegistryException::fileMissing($path);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw ModelRegistryException::fileMissing($path);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ModelRegistryException::invalidJson($e->getMessage());
        }

        if (! is_array($data)) {
            throw ModelRegistryException::invalidSchema('root must be a JSON object');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $document  Full source document (schema_version, capability arrays, optional _meta)
     */
    public function writeRaw(array $document): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            throw new \RuntimeException('models.json directory does not exist: '.$dir);
        }

        try {
            $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $e) {
            throw ModelRegistryException::invalidJson($e->getMessage());
        }

        $previous = is_readable($path) ? file_get_contents($path) : false;

        // Write the temp file under storage/framework — not next to models.json. Many Apache/php-fpm
        // setups can read config/models.json but cannot create files in config/, which caused 500s.
        $frameworkDir = storage_path('framework');
        if (! is_dir($frameworkDir)) {
            if (! @mkdir($frameworkDir, 0755, true) && ! is_dir($frameworkDir)) {
                throw new \RuntimeException('Cannot create storage/framework for models.json temp file.');
            }
        }
        $tmp = $frameworkDir.'/models-json.'.bin2hex(random_bytes(8)).'.tmp';
        if (file_put_contents($tmp, $encoded) === false) {
            throw new \RuntimeException(
                'Failed to write temporary models.json under storage/framework. Check storage/ is writable by the web server.',
            );
        }

        try {
            new ModelRegistry($tmp, app(ProviderRegistry::class));
        } catch (ModelRegistryException $e) {
            @unlink($tmp);
            throw $e;
        }

        if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
            @unlink($path);
        }

        $replaced = @rename($tmp, $path);
        if (! $replaced) {
            // Different volume or target locked: try non-atomic copy (still validated above).
            $replaced = @copy($tmp, $path);
            @unlink($tmp);
        } else {
            $tmp = '';
        }

        if (! $replaced) {
            if ($tmp !== '' && is_file($tmp)) {
                @unlink($tmp);
            }
            if ($previous !== false) {
                @file_put_contents($path, $previous);
            }
            throw new \RuntimeException(
                'Could not write models.json to '.$path.'. Grant the web server user write access to this file, '.
                'or set TUTOR_MODELS_JSON_PATH in .env to a writable path (for example storage/app/models.json).',
            );
        }

    }
}
