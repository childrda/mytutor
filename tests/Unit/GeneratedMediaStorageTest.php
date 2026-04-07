<?php

namespace Tests\Unit;

use App\Services\MediaGeneration\GeneratedMediaStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeneratedMediaStorageTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function store_binary_writes_under_kind_and_date_and_returns_paths(): void
    {
        $mock = Mockery::mock(Filesystem::class);
        $mock->shouldReceive('put')
            ->once()
            ->withArgs(function (string $path, string $contents, array $options): bool {
                return str_starts_with($path, 'generated-test/image/')
                    && preg_match('#^generated-test/image/\d{4}/\d{2}/\d{2}/[0-9a-z]{26}\.png$#', $path) === 1
                    && $contents === 'fake-png-bytes'
                    && ($options['visibility'] ?? null) === 'public';
            })
            ->andReturnTrue();
        $mock->shouldReceive('url')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn('https://app.test/storage/generated-test/x.png');

        $svc = new GeneratedMediaStorage('public', 'generated-test', $mock);
        $out = $svc->storeBinary('image', 'png', 'fake-png-bytes');

        $this->assertStringStartsWith('generated-test/image/', $out['relativePath']);
        $this->assertStringEndsWith('.png', $out['relativePath']);
        $this->assertSame('https://app.test/storage/generated-test/x.png', $out['url']);
    }

    #[Test]
    public function rejects_empty_binary(): void
    {
        $mock = Mockery::mock(Filesystem::class);
        $mock->shouldReceive('put')->never();

        $this->expectException(InvalidArgumentException::class);
        (new GeneratedMediaStorage('public', 'g', $mock))->storeBinary('tts', 'mp3', '');
    }

    #[Test]
    public function rejects_invalid_kind(): void
    {
        $mock = Mockery::mock(Filesystem::class);
        $mock->shouldReceive('put')->never();

        $this->expectException(InvalidArgumentException::class);
        (new GeneratedMediaStorage('public', 'g', $mock))->storeBinary('../evil', 'bin', 'x');
    }

    #[Test]
    public function get_or_store_fingerprint_writes_once_and_hits_on_same_key(): void
    {
        $mock = Mockery::mock(Filesystem::class);
        $mock->shouldReceive('exists')
            ->twice()
            ->andReturn(false, true);
        $mock->shouldReceive('put')
            ->once()
            ->withArgs(function (string $path, string $contents, array $options): bool {
                return str_contains($path, 'generated-test/tts-cache/')
                    && str_contains($path, '.mp3')
                    && $contents === 'bytes-a'
                    && ($options['visibility'] ?? null) === 'public';
            })
            ->andReturnTrue();
        $mock->shouldReceive('url')
            ->twice()
            ->andReturn('https://app.test/storage/cached.mp3');

        $svc = new GeneratedMediaStorage('public', 'generated-test', $mock);
        $a = $svc->getOrStoreFingerprint('tts-cache', 'same-key', 'mp3', fn () => 'bytes-a');
        $this->assertFalse($a['cacheHit']);
        $b = $svc->getOrStoreFingerprint('tts-cache', 'same-key', 'mp3', fn () => 'bytes-b');
        $this->assertTrue($b['cacheHit']);
    }
}
