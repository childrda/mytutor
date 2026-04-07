<?php

namespace App\Services\MediaGeneration;

use RuntimeException;

final class VideoGenerationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus = 502,
    ) {
        parent::__construct($message);
    }
}
