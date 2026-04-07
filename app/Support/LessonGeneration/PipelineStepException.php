<?php

namespace App\Support\LessonGeneration;

use RuntimeException;
use Throwable;

final class PipelineStepException extends RuntimeException
{
    public function __construct(
        public readonly string $step,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
