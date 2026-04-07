<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies Laravel's throttle to POST /api/generate/* using runtime config (Phase 4.6).
 */
final class ThrottleGenerateRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $per = (int) config('tutor.generate.throttle_per_minute', 60);
        if ($per <= 0) {
            return $next($request);
        }

        return app(ThrottleRequests::class)->handle($request, $next, $per, 1, 'generate');
    }
}
