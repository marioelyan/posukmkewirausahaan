<?php

namespace App\Support\Tenancy;

use App\Models\BusinessUser;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * AUTH-01/02/03 — business-scoped capability boundary.
 *
 * Spatie runs with `teams=false`, so permissions live on the user globally.
 * Inside an active business the effective capability set must instead come
 * from the membership role the user holds THERE (AUTH-02: role per business
 * membership), never from their platform-wide grants (AUTH-03: capability
 * engine). Otherwise a permission earned in one business could be exercised
 * inside another business where the user is only a lower-role member.
 *
 * Business role → platform capability set:
 *  - owner   → everything except cross-business platform administration
 *              (users / roles / permissions / financial ownership);
 *  - manager → the Spatie `manager` role (full operational scope, no admin);
 *  - staff   → the Spatie `cashier` role (POS + read-mostly operations).
 *
 * The mapping resolves the LIVE Spatie roles at runtime, so permission changes
 * in `RoleSeeder` are picked up automatically. No new permissions (or Spatie
 * roles) are introduced; the FIN-04 constraint "no new permissions" holds.
 */
class BusinessRoleCapabilities
{
    /**
     * Platform-only permission patterns never granted to any business role.
     * These govern cross-business administration that belongs to the platform
     * super-admin path (AUTH-01), not to any single business ownership.
     */
    private const PLATFORM_ONLY = [
        'users-*',
        'roles-*',
        'permissions-*',
        'financial-ownership-*',
    ];

    public function grants(BusinessUser $membership, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($membership->role), true);
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(?string $businessRole): array
    {
        if ($businessRole === BusinessUser::ROLE_OWNER) {
            return Permission::query()
                ->pluck('name')
                ->reject(fn (string $name) => Str::is(self::PLATFORM_ONLY, $name))
                ->values()
                ->all();
        }

        $spatieRole = match ($businessRole) {
            BusinessUser::ROLE_MANAGER => 'manager',
            BusinessUser::ROLE_STAFF => 'cashier',
            default => null,
        };

        if ($spatieRole === null) {
            return [];
        }

        $role = Role::query()->where('name', $spatieRole)->first();

        return $role?->permissions()->pluck('name')->all() ?? [];
    }
}
