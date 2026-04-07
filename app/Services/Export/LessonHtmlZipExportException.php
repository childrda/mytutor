<?php

namespace App\Services\Export;

use RuntimeException;

final class LessonHtmlZipExportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
