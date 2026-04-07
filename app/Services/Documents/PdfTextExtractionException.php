<?php

namespace App\Services\Documents;

use RuntimeException;

final class PdfTextExtractionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
