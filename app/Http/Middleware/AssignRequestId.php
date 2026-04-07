<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Propagates X-Request-Id for tracing (Phase 6). Accepts client id when sane; otherwise generates UUID.
 */
final class AssignRequestId
{
    private const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $id = is_string($incoming) && $incoming !== '' && preg_match('/^[a-zA-Z0-9._-]{1,128}$/', $incoming)
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
