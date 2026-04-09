<?php

namespace App\Services\Ai;

/**
 * Outcome of {@see ModelRegistryHttpExecutor::execute()} (Phase 3).
 */
final readonly class ModelRegistryHttpResult
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public int $status,
        public bool $successful,
        public array $headers,
        public ?array $json,
        public string $rawBody,
        public mixed $extracted,
    ) {}
}
