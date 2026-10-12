<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Services\BusinessContext;
use App\Services\BusinessMembershipResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveBusinessContext
{
    public function __construct(
        private readonly BusinessContext $context,
        private readonly BusinessMembershipResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();

        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $requestedId = $this->requestedBusinessId($request);

        if ($requestedId !== null) {
            if ($user->isSuperAdmin()) {
                $business = Business::query()
                    ->whereKey($requestedId)
                    ->where('status', Business::STATUS_ACTIVE)
                    ->first();

                if ($business) {
                    $this->context->setActive($business);
                }
            } else {
                $membership = $this->resolver->activeMembershipFor($user, $requestedId);

                if ($membership) {
                    $this->context->setActive($membership->business, $membership);
                }
            }

            return $next($request);
        }

        // Super-admins without an explicit selection fall back to the first
        // active business (Phase 8 UI). Only while tenancy is enabled — in
        // legacy mode no context is resolved and nothing changes.
        if (config('multi_business.enabled') && $user->isSuperAdmin()) {
            $business = Business::query()
                ->where('status', Business::STATUS_ACTIVE)
                ->orderBy('id')
                ->first();

            if ($business) {
                $this->context->setActive($business);
            }
        }

        if (! $user->isSuperAdmin()) {
            $membership = $this->resolver->soleActiveMembershipFor($user);

            if ($membership) {
                $this->context->setActive($membership->business, $membership);
            }
        }

        return $next($request);
    }

    private function requestedBusinessId(Request $request): ?int
    {
        if ($request->hasSession()) {
            $value = $request->session()->get('active_business_id');

            if ($value !== null) {
                return (int) $value;
            }
        }

        // Stateless API clients select a business via a header. The value is not
        // trusted as-is: it is resolved through the membership resolver (or an
        // active-business check for super-admins) just like the session value.
        $header = $request->header('X-Business-Id');

        if (is_string($header) && $header !== '' && ctype_digit($header)) {
            return (int) $header;
        }

        return null;
    }
}
