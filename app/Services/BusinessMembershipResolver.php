<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Support\Collection;

class BusinessMembershipResolver
{
    public function activeMembershipFor(User $user, int $businessId): ?BusinessUser
    {
        $membership = $user->memberships()
            ->active()
            ->forBusiness($businessId)
            ->with('business')
            ->first();

        if (! $membership || ! $membership->business) {
            return null;
        }

        if ($membership->business->status !== Business::STATUS_ACTIVE) {
            return null;
        }

        return $membership;
    }

    public function isActiveMember(User $user, int $businessId): bool
    {
        return $this->activeMembershipFor($user, $businessId) !== null;
    }

    public function activeMembershipsFor(User $user): Collection
    {
        return $user->memberships()
            ->active()
            ->whereHas('business', fn ($query) => $query->where('status', Business::STATUS_ACTIVE))
            ->with('business')
            ->get();
    }

    public function soleActiveMembershipFor(User $user): ?BusinessUser
    {
        $memberships = $this->activeMembershipsFor($user);

        return $memberships->count() === 1 ? $memberships->first() : null;
    }

    /**
     * The membership that owns the user's currently open shift, if any.
     *
     * A shift pins the operator to one business: POS actions only pass the
     * active-shift gate while the active business matches the shift's business,
     * and the business switcher is locked mid-shift. Placing the user anywhere
     * else would therefore strand them — able to see the app but unable to sell
     * and unable to switch back. The open shift consequently outranks the
     * role-based {@see preferredActiveMembershipFor()} default.
     *
     * Cross-business on purpose: at login there is no context yet, and the shift
     * may belong to a business other than the one we would otherwise pick.
     */
    public function membershipOwningOpenShiftFor(User $user): ?BusinessUser
    {
        $businessId = CashierShift::query()
            ->withoutGlobalScope('business')
            ->open()
            ->where('user_id', $user->id)
            ->latest('opened_at')
            ->value('business_id');

        if ($businessId === null) {
            return null;
        }

        return $this->activeMembershipFor($user, (int) $businessId);
    }

    /**
     * Deterministic default for a user who belongs to several businesses.
     *
     * Used on login so a member is always placed in a business they actually
     * own rather than being dropped into a contextless 403 (the switcher is
     * rendered inside the guarded dashboard, so "no context" would be a
     * dead-end with no way to pick one). Preference is owner > manager > staff,
     * then the oldest membership, so the result never changes between runs.
     *
     * Every request still re-verifies the selection against the membership, so
     * this only ever chooses between businesses the user is entitled to.
     */
    public function preferredActiveMembershipFor(User $user): ?BusinessUser
    {
        $memberships = $this->activeMembershipsFor($user);

        if ($memberships->isEmpty()) {
            return null;
        }

        $rank = [
            BusinessUser::ROLE_OWNER => 0,
            BusinessUser::ROLE_MANAGER => 1,
            BusinessUser::ROLE_STAFF => 2,
        ];

        return $memberships
            ->sort(fn (BusinessUser $a, BusinessUser $b) => [
                $rank[$a->role] ?? 3,
                (int) $a->id,
            ] <=> [
                $rank[$b->role] ?? 3,
                (int) $b->id,
            ])
            ->first();
    }

    public function accessibleBusinesses(User $user): Collection
    {
        return Business::query()
            ->whereIn('id', $this->activeMembershipsFor($user)->pluck('business_id'))
            ->orderBy('name')
            ->get();
    }
}
