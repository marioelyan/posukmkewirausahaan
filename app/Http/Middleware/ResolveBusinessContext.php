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
    /**
     * The selected business came from the web session (`business.switch`), i.e.
     * a value the app wrote for the person sitting in front of the browser.
     */
    private const SOURCE_SESSION = 'session';

    /**
     * The selected business came from the stateless `X-Business-Id` header used
     * by API clients, which has to stay strict: a request that explicitly asks
     * for a business it is not entitled to must never silently fall back to a
     * different one.
     */
    private const SOURCE_HEADER = 'header';

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

        [$requestedId, $source] = $this->requestedBusinessId($request);
        $selectionCleared = false;

        if ($requestedId !== null) {
            if ($user->isSuperAdmin()) {
                $business = Business::query()
                    ->whereKey($requestedId)
                    ->where('status', Business::STATUS_ACTIVE)
                    ->first();

                if ($business) {
                    $this->context->setActive($business);

                    return $next($request);
                }
            } else {
                $membership = $this->resolver->activeMembershipFor($user, $requestedId);

                if ($membership) {
                    $this->context->setActive($membership->business, $membership);

                    return $next($request);
                }
            }

            // The selection can no longer be verified: the membership was
            // revoked, the business suspended or the row belongs to someone
            // else. Dropping it lets the request recover through the normal
            // resolution below instead of failing closed on a value nothing
            // ever clears — previously that was a permanent 403 for the
            // session, super-admins included.
            if ($source === self::SOURCE_SESSION && $request->hasSession()) {
                $request->session()->forget('active_business_id');
                $selectionCleared = true;
            } else {
                return $next($request);
            }
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
            // A member whose selection just went stale had already been working
            // inside their businesses, so re-place them in one they are still
            // entitled to instead of dumping them back into a contextless 403.
            // Without a stale selection the resolver stays strict: several
            // memberships still require an explicit choice.
            $membership = $selectionCleared
                ? ($this->resolver->membershipOwningOpenShiftFor($user)
                    ?? $this->resolver->preferredActiveMembershipFor($user))
                : $this->resolver->soleActiveMembershipFor($user);

            if ($membership) {
                $this->context->setActive($membership->business, $membership);

                if ($selectionCleared) {
                    $request->session()->put('active_business_id', $membership->business_id);
                }
            }
        }

        return $next($request);
    }

    /**
     * @return array{0: ?int, 1: ?string} the requested business id and its source
     */
    private function requestedBusinessId(Request $request): array
    {
        if ($request->hasSession()) {
            $value = $request->session()->get('active_business_id');

            if ($value !== null) {
                return [(int) $value, self::SOURCE_SESSION];
            }
        }

        // Stateless API clients select a business via a header. The value is not
        // trusted as-is: it is resolved through the membership resolver (or an
        // active-business check for super-admins) just like the session value.
        $header = $request->header('X-Business-Id');

        if (is_string($header) && $header !== '' && ctype_digit($header)) {
            return [(int) $header, self::SOURCE_HEADER];
        }

        return [null, null];
    }
}
