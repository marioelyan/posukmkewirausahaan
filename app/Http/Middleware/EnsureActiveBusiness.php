<?php

namespace App\Http\Middleware;

use App\Services\BusinessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveBusiness
{
    public function __construct(private readonly BusinessContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Legacy / single-business mode: no business context is required and the
        // middleware is a no-op so existing routes keep working unchanged.
        if (! config('multi_business.enabled')) {
            return $next($request);
        }

        if (! $this->context->hasActiveBusiness()) {
            abort(403, 'Aktif business context is required for this request.');
        }

        return $next($request);
    }
}
