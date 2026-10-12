<?php

namespace App\Support\Tenancy;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Provision `business_user` memberships from the data that already exists on a
 * legacy / imported install: each user's outlet assignments decide which
 * business(es) they belong to, and their platform role decides the business
 * role they receive.
 *
 * This is the missing half of the tenancy rollout. `business:backfill` stamps
 * `business_id` on master data, outlets and warehouses, but nothing creates
 * memberships — without them a non-super user has no verified business context
 * and is rejected (403) as soon as `multi_business.enabled` is turned on.
 *
 * Role mapping (platform Spatie role -> business membership role):
 *  - super-admin -> no membership (explicit platform path, AUTH-01);
 *  - owner       -> owner  (business administration within its own business);
 *  - manager     -> manager (full operational scope);
 *  - cashier     -> staff  (POS + read-mostly operations);
 *  - anything else is skipped and must be assigned manually (fail closed).
 *
 * The provisioner is idempotent: an existing (business, user) membership is
 * never overwritten, so re-running cannot silently change roles.
 */
class BusinessMembershipProvisioner
{
    /**
     * @return string|null business role, or null when the user must be skipped
     */
    public function businessRoleFor(User $user): ?string
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        if ($user->hasRole('owner')) {
            return BusinessUser::ROLE_OWNER;
        }

        if ($user->hasRole('manager')) {
            return BusinessUser::ROLE_MANAGER;
        }

        if ($user->hasRole('cashier')) {
            return BusinessUser::ROLE_STAFF;
        }

        return null;
    }

    /**
     * Build (without writing) the membership plan derived from outlet access.
     *
     * @return Collection<int, array{
     *     user_id:int, email:string, name:string,
     *     business_id:int, business:string,
     *     role:string|null, action:string
     * }>
     */
    public function plan(?Business $business = null): Collection
    {
        $businesses = $business
            ? collect([$business])
            : Business::query()->orderBy('id')->get();

        $rows = collect();

        foreach ($businesses as $target) {
            $outletIds = $target->outlets()->pluck('id');

            if ($outletIds->isEmpty()) {
                continue;
            }

            $users = User::query()
                ->whereHas('outlets', fn ($query) => $query->whereIn('outlets.id', $outletIds))
                ->with('roles')
                ->orderBy('id')
                ->get();

            foreach ($users as $user) {
                $role = $this->businessRoleFor($user);

                $exists = BusinessUser::query()
                    ->where('business_id', $target->id)
                    ->where('user_id', $user->id)
                    ->exists();

                $rows->push([
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                    'business_id' => $target->id,
                    'business' => $target->name,
                    'role' => $role,
                    'action' => match (true) {
                        $role === null => 'skip',
                        $exists => 'exists',
                        default => 'create',
                    },
                ]);
            }
        }

        return $rows;
    }

    /**
     * Write the plan. Existing memberships are left untouched.
     *
     * @return array{created:int, skipped:int, unmapped:int}
     */
    public function apply(?Business $business = null): array
    {
        $created = 0;
        $skipped = 0;
        $unmapped = 0;

        foreach ($this->plan($business) as $row) {
            if ($row['action'] === 'skip') {
                $unmapped++;

                continue;
            }

            if ($row['action'] === 'exists') {
                $skipped++;

                continue;
            }

            BusinessUser::create([
                'business_id' => $row['business_id'],
                'user_id' => $row['user_id'],
                'role' => $row['role'],
                'status' => BusinessUser::STATUS_ACTIVE,
                'default_outlet_id' => $this->defaultOutletId($row['user_id'], $row['business_id']),
                'joined_at' => now(),
            ]);

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'unmapped' => $unmapped];
    }

    private function defaultOutletId(int $userId, int $businessId): ?int
    {
        return User::query()
            ->find($userId)
            ?->outlets()
            ->where('outlets.business_id', $businessId)
            ->orderByDesc('user_outlets.is_default')
            ->orderBy('outlets.id')
            ->value('outlets.id');
    }
}
