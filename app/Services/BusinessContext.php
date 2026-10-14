<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Outlet;
use App\Models\User;

class BusinessContext
{
    private ?Business $business = null;

    private ?BusinessUser $membership = null;

    public function setActive(Business $business, ?BusinessUser $membership = null): void
    {
        $this->business = $business;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->business = null;
        $this->membership = null;
    }

    public function hasActiveBusiness(): bool
    {
        return $this->business !== null;
    }

    public function business(): ?Business
    {
        return $this->business;
    }

    public function businessId(): ?int
    {
        return $this->business?->id;
    }

    public function membership(): ?BusinessUser
    {
        return $this->membership;
    }

    public function role(): ?string
    {
        return $this->membership?->role;
    }

    public function isActiveMembership(): bool
    {
        return $this->membership !== null
            && $this->membership->status === BusinessUser::STATUS_ACTIVE;
    }

    public function belongsToUser(User $user): bool
    {
        return $this->business !== null
            && $this->membership !== null
            && (int) $this->membership->user_id === (int) $user->id;
    }

    public function outletIsInActiveBusiness(Outlet $outlet): bool
    {
        return $this->business !== null
            && $outlet->business_id !== null
            && (int) $outlet->business_id === (int) $this->business->id;
    }
}
