<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class ProxyMediaController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $url = (string) $request->input('url', '');
        if ($url === '' || ! Str::startsWith($url, ['http://', 'https://'])) {
            return ApiJson::error(ApiJson::INVALID_URL, 400, 'A valid http(s) url is required');
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return ApiJson::error(ApiJson::INVALID_URL, 400, 'Invalid url');
        }

        $hostBlock = self::proxyHostNotAllowedReason($host);
        if ($hostBlock !== null) {
            return ApiJson::error(ApiJson::INVALID_URL, 400, $hostBlock);
        }

        try {
            $res = Http::timeout(30)->withHeaders([
                'User-Agent' => 'MyTutorMediaProxy/1.0',
            ])->get($url);

            if (! $res->successful()) {
                return ApiJson::error(
                    ApiJson::UPSTREAM_ERROR,
                    502,
                    'Upstream request failed',
                    (string) $res->status(),
                );
            }

            $contentType = $res->header('Content-Type') ?: 'application/octet-stream';
            $body = $res->body();

            return ApiJson::success([
                'contentType' => $contentType,
                'data' => base64_encode($body),
            ]);
        } catch (Throwable $e) {
            return ApiJson::error(
                ApiJson::UPSTREAM_ERROR,
                502,
                'Proxy failed',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return string|null Error message for client, or null if the host is allowed
     */
    private static function proxyHostNotAllowedReason(string $host): ?string
    {
        $hostLower = strtolower($host);
        if ($hostLower === 'localhost' || str_ends_with($hostLower, '.local') || str_ends_with($hostLower, '.internal')) {
            return 'Private network hosts are not allowed';
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'Private network hosts are not allowed';
            }

            return null;
        }

        $resolved = @gethostbyname($host);
        if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
            if (! filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'Private network hosts are not allowed';
            }
        }

        return null;
    }
}
