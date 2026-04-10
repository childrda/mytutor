<?php

namespace App\Services\Ai;

use RuntimeException;

final class ProviderRegistryException extends RuntimeException
{
    public static function fileMissing(string $path): self
    {
        return new self('Provider registry file not found: '.$path);
    }

    public static function invalidJson(string $message): self
    {
        return new self('Provider registry JSON invalid: '.$message);
    }

    public static function invalidSchema(string $detail): self
    {
        return new self('Provider registry schema error: '.$detail);
    }

    public static function unknownProvider(string $id): self
    {
        return new self("Unknown provider catalog id \"{$id}\".");
    }
}
