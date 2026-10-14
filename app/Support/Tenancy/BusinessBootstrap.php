<?php

namespace App\Support\Tenancy;

use App\Models\Business;
use Illuminate\Support\Str;

/**
 * First-install tenancy bootstrap.
 *
 * Tenancy is fail-closed: with `multi_business.enabled=true` and zero
 * businesses, even a super-admin cannot resolve a context and the dashboard is
 * rejected (403). A fresh install therefore has to end up with at least one
 * business that owns its outlets/warehouses/master data.
 *
 * Q6 phase 1 says only the platform admin creates businesses, and the setup
 * wizard already creates that admin (`super-admin`). So setup simply
 * bootstraps the installation's single default business (Q7: one install = one
 * business with many branches) and scopes the freshly created data to it. No
 * user-facing business creation is added here; self-service owner signup stays
 * a later, separately-decided phase.
 *
 * Idempotent: an existing business is reused and only still-unscoped rows are
 * touched.
 */
class BusinessBootstrap
{
    public function __construct(private readonly BusinessDataScoper $scoper) {}

    /**
     * Ensure a default business exists, then scope its still-unowned data.
     */
    public function bootstrap(string $name): Business
    {
        $business = $this->ensureDefaultBusiness($name);

        $this->scoper->assignUnscoped($business->id);

        return $business;
    }

    /**
     * Return the installation's business, creating it on first install.
     */
    public function ensureDefaultBusiness(string $name): Business
    {
        $existing = Business::query()->orderBy('id')->first();

        if ($existing) {
            return $existing;
        }

        $name = trim($name) !== ''
            ? trim($name)
            : (string) config('multi_business.default_name');

        return Business::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => Business::STATUS_ACTIVE,
            'currency' => 'IDR',
            'timezone' => config('app.timezone', 'Asia/Jakarta'),
        ]);
    }
}
