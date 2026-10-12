<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Q6-A — platform administration boundary.
 *
 * Only the platform super-admin (the explicit platform path, AUTH-01) may
 * manage businesses across the installation. Business roles never hold
 * platform administration capabilities (see
 * BusinessRoleCapabilities::PLATFORM_ONLY), and this gate is role-based, so no
 * new Spatie permission is introduced (the FIN-04 "no new permissions"
 * constraint holds).
 *
 * The gate is intentionally independent of `business.required`: managing
 * businesses must keep working even when no active business context exists
 * (e.g. before the first business is activated, or to un-suspend a suspended
 * install).
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            abort(403, 'Only the platform administrator can manage businesses.');
        }

        return $next($request);
    }
}
