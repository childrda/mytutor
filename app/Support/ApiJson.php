<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiJson
{
    public const MISSING_REQUIRED_FIELD = 'MISSING_REQUIRED_FIELD';

    public const MISSING_API_KEY = 'MISSING_API_KEY';

    public const INVALID_REQUEST = 'INVALID_REQUEST';

    public const INVALID_URL = 'INVALID_URL';

    public const UPSTREAM_ERROR = 'UPSTREAM_ERROR';

    public const GENERATION_FAILED = 'GENERATION_FAILED';

    public const CONTENT_SENSITIVE = 'CONTENT_SENSITIVE';

    public const TRANSCRIPTION_FAILED = 'TRANSCRIPTION_FAILED';

    public const PARSE_FAILED = 'PARSE_FAILED';

    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, ...$data], $status);
    }

    public static function error(
        string $code,
        int $status,
        string $message,
        ?string $details = null,
    ): JsonResponse {
        $body = [
            'success' => false,
            'errorCode' => $code,
            'error' => $message,
        ];
        if ($details !== null) {
            $body['details'] = $details;
        }

        return response()->json($body, $status);
    }
}
