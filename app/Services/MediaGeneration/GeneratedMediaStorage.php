<?php

namespace App\Services\MediaGeneration;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Persists generated binaries (images, audio, video) under a dated prefix (Phase 4.1).
 */
class GeneratedMediaStorage
{
    public function __construct(
        private readonly string $diskName,
        private readonly string $pathPrefix,
        private readonly ?Filesystem $filesystem = null,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('tutor.media_generation.disk', 'public'),
            trim((string) config('tutor.media_generation.path_prefix', 'generated'), '/'),
            null,
        );
    }

    private function disk(): Filesystem
    {
        return $this->filesystem ?? Storage::disk($this->diskName);
    }

    /**
     * @return array{relativePath: string, url: string}
     */
    public function storeBinary(string $kind, string $extension, string $binary): array
    {
        if ($binary === '') {
            throw new InvalidArgumentException('Empty binary');
        }

        $kind = $this->validateKind($kind);
        $extension = $this->normalizeExtension($extension);

        $relative = $this->pathPrefix.'/'.$kind.'/'.now()->format('Y/m/d').'/'
            .Str::lower(Str::ulid()).'.'.$extension;

        $this->disk()->put(
            $relative,
            $binary,
            ['visibility' => 'public'],
        );

        $url = $this->disk()->url($relative);

        return [
            'relativePath' => $relative,
            'url' => $url,
        ];
    }

    private function validateKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        if (! preg_match('/^[a-z0-9_-]{1,32}$/', $kind)) {
            throw new InvalidArgumentException('Invalid storage kind');
        }

        return $kind;
    }

    private function normalizeExtension(string $extension): string
    {
        $extension = strtolower(ltrim(trim($extension), '.'));
        if (! preg_match('/^[a-z0-9]{1,8}$/', $extension)) {
            throw new InvalidArgumentException('Invalid file extension');
        }

        return $extension;
    }
}
