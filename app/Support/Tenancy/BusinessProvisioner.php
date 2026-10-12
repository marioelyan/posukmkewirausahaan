<?php

namespace App\Support\Tenancy;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use App\Services\BusinessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Q6-A — provision a brand-new tenant from the platform admin panel.
 *
 * This differs deliberately from the first-install bootstrap
 * (BusinessBootstrap): it creates a FRESH business and never runs
 * BusinessDataScoper::assignUnscoped, because that helper maps every
 * still-unscoped row to the given business and would steal unbackfilled legacy
 * rows. Instead every scoped row is stamped with the new business's id
 * explicitly, so the skeleton is tenant-correct regardless of the
 * `multi_business` flag state (when the flag is off the
 * BelongsToBusiness creating hook is inert and the explicit value persists;
 * when it is on, the active context is temporarily switched to the new
 * business so the hook writes the same id).
 *
 * The skeleton mirrors the first-install layout: one central non-sales PUSAT
 * outlet with its main warehouse, plus an optional owner membership (owner
 * role, active, default outlet = PUSAT). Super-admins cannot be owners — they
 * already have the explicit platform path (AUTH-01) and
 * BusinessMembershipProvisioner skips them (fail closed).
 */
class BusinessProvisioner
{
    public function __construct(
        private readonly BusinessContext $context,
        private readonly AuditLogService $auditLog,
    ) {}

    public function provision(string $name, ?User $owner = null): Business
    {
        return DB::transaction(function () use ($name, $owner) {
            $business = Business::create([
                'name' => trim($name),
                'slug' => Str::slug(trim($name)).'-'.Str::lower(Str::random(6)),
                'owner_user_id' => $owner?->id,
                'status' => Business::STATUS_ACTIVE,
                'currency' => 'IDR',
                'timezone' => config('app.timezone', 'Asia/Jakarta'),
            ]);

            $previous = $this->context->business();
            $this->context->setActive($business);

            try {
                $pusat = Outlet::create([
                    'business_id' => $business->id,
                    'code' => 'PUSAT',
                    'name' => 'Gudang Pusat',
                    'is_active' => true,
                    'is_sales_enabled' => false,
                ]);

                Warehouse::create([
                    'business_id' => $business->id,
                    'outlet_id' => $pusat->id,
                    'code' => 'PUSAT',
                    'name' => 'Gudang Pusat',
                    'type' => 'main',
                    'is_active' => true,
                    'sort_order' => 0,
                ]);

                if ($owner) {
                    BusinessUser::create([
                        'business_id' => $business->id,
                        'user_id' => $owner->id,
                        'role' => BusinessUser::ROLE_OWNER,
                        'status' => BusinessUser::STATUS_ACTIVE,
                        'default_outlet_id' => $pusat->id,
                        'joined_at' => now(),
                    ]);
                }

                $this->auditLog->log(
                    event: 'business.created',
                    module: 'business',
                    auditable: $business,
                    description: 'Business baru dibuat oleh platform admin.',
                    after: [
                        'name' => $business->name,
                        'slug' => $business->slug,
                        'owner_user_id' => $owner?->id,
                    ],
                    meta: ['provisioned' => true],
                );
            } finally {
                if ($previous) {
                    $this->context->setActive($previous);
                } else {
                    $this->context->clear();
                }
            }

            return $business;
        });
    }
}
