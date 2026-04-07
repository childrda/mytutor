<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-route throttle using config tutor.throttle.{name}.per_minute (Phase 6).
 */
final class ThrottleTutorRequests
{
    public function handle(Request $request, Closure $next, string $name): Response
    {
        $per = (int) config("tutor.throttle.{$name}.per_minute", 60);
        if ($per <= 0) {
            return $next($request);
        }

        return app(ThrottleRequests::class)->handle($request, $next, $per, 1, $name);
    }
}
