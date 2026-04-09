<?php

namespace App\Services\Ai;

use RuntimeException;

final class ModelRegistryException extends RuntimeException
{
    public static function fileMissing(string $path): self
    {
        return new self('Model registry file not found: '.$path);
    }

    public static function invalidJson(string $message): self
    {
        return new self('Model registry JSON invalid: '.$message);
    }

    public static function invalidSchema(string $detail): self
    {
        return new self('Model registry schema error: '.$detail);
    }

    public static function unknownCapability(string $capability, string $allowed): self
    {
        return new self("Unknown model registry capability \"{$capability}\". Allowed: {$allowed}.");
    }

    public static function unknownProvider(string $capability, string $key): self
    {
        return new self("Unknown model registry provider \"{$key}\" under capability \"{$capability}\".");
    }
}
