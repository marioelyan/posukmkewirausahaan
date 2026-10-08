<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNotInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Setting::getBool('app_setup_completed', false)) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
