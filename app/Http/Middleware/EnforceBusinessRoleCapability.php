<?php

namespace App\Http\Middleware;

use App\Models\BusinessUser;
use App\Services\BusinessContext;
use App\Support\Tenancy\BusinessRoleCapabilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUTH-02/03 — per-business role capability enforcement.
 *
 * Spatie's `permission` middleware checks the user's GLOBAL grants. With
 * `teams=false` a user who holds a permission in one business could use it in
 * another business where they are only a lower-role member. This middleware
 * closes that gap: when tenancy is enabled it verifies that every capability
 * the route requires (`permission:` on the dashboard, `abilities:` on the
 * REST API) is granted by the ACTIVE membership's business role.
 *
 * Platform super-admins keep the explicit platform path (AUTH-01) and are
 * never constrained by a membership. While `multi_business.enabled` is false
 * the middleware is a no-op, preserving legacy single-business behaviour.
 */
class EnforceBusinessRoleCapability
{
    public function __construct(
        private readonly BusinessContext $context,
        private readonly BusinessRoleCapabilities $capabilities,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('multi_business.enabled')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user || $user->isSuperAdmin()) {
            return $next($request);
        }

        // No active membership: `business.required` already failed closed when a
        // context is missing, and the super-admin fallback sets no membership.
        $membership = $this->context->membership();

        if (! $membership || $membership->status !== BusinessUser::STATUS_ACTIVE) {
            return $next($request);
        }

        foreach ($this->requiredCapabilities($request) as $capability) {
            if (! $this->capabilities->grants($membership, $capability)) {
                abort(403, 'Capability not granted for the role in the active business.');
            }
        }

        return $next($request);
    }

    /**
     * Extract `permission:` and `abilities:` entries from the current route's
     * middleware list. `permission:` guards the dashboard, `abilities:` guards
     * the Sanctum API; both mirror Spatie permission names.
     *
     * @return list<string>
     */
    private function requiredCapabilities(Request $request): array
    {
        $required = [];

        foreach ($request->route()?->gatherMiddleware() ?? [] as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            foreach (['permission:', 'abilities:'] as $prefix) {
                if (str_starts_with($middleware, $prefix)) {
                    $required[] = substr($middleware, strlen($prefix));
                }
            }
        }

        return $required;
    }
}
