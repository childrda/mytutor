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
        return new self(
            "Unknown models.json model id \"{$key}\" for capability \"{$capability}\". "
            .'Use the row `id` (registry slug), not the API `provider` field — e.g. `openai-gpt-4o` must appear under `llm` in models.json and be enabled.',
        );
    }

    public static function missingVariable(string $name): self
    {
        return new self("Missing template variable \"{$name}\" for model registry request (no default in template).");
    }

    public static function invalidTemplate(string $detail): self
    {
        return new self('Model registry template error: '.$detail);
    }

    public static function invalidProviderEntry(string $detail): self
    {
        return new self('Invalid model registry provider entry: '.$detail);
    }

    public static function requestEncodingNotSupported(string $encoding): self
    {
        return new self("Model registry request_encoding \"{$encoding}\" is not supported yet.");
    }

    public static function httpFailed(int $status, string $preview): self
    {
        return new self("Model registry HTTP request failed ({$status}): ".$preview);
    }

    public static function activeSelectionNotConfigured(string $capability): self
    {
        return new self(
            'No active model registry key configured for capability "'.$capability.'". '
            .'Set the matching env var (e.g. TUTOR_ACTIVE_LLM), save a provider in Settings (global DB), or config tutor.active.'.$capability.'.',
        );
    }
}
