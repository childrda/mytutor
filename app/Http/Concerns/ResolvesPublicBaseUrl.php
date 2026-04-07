<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;

trait ResolvesPublicBaseUrl
{
    protected function publicBaseUrl(Request $request): string
    {
        $forwarded = $request->headers->get('x-forwarded-host');
        if ($forwarded) {
            $proto = $request->headers->get('x-forwarded-proto') ?: 'http';

            return $proto.'://'.$forwarded;
        }

        return $request->getSchemeAndHttpHost();
    }
}
