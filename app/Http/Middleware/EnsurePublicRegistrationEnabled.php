<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePublicRegistrationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.auth.public_registration')) {
            abort(404);
        }

        return $next($request);
    }
}
