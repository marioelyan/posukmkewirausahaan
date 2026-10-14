<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessUser;
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

    public function accessibleBusinesses(User $user): Collection
    {
        return Business::query()
            ->whereIn('id', $this->activeMembershipsFor($user)->pluck('business_id'))
            ->orderBy('name')
            ->get();
    }
}
