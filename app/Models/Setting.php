<?php

namespace App\Models;

use App\Exceptions\TenantSettingScopeException;
use App\Services\BusinessContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'business_id',
        'outlet_id',
        'value',
        'description',
    ];

    /**
     * Settings that are genuinely platform-wide and always use the single
     * global row (`business_id = null`, `outlet_id = null`), even while
     * multi-business is enabled (Q5 / ADR-04, SETTINGS-07).
     *
     * @var array<int, string>
     */
    private const PLATFORM_KEYS = [
        'app_setup_completed',
        'setup_warehouse_id',
    ];

    /**
     * Whether reads/writes should be scoped by business_id. Requires both the
     * flag and the ADR-04 column; otherwise the legacy global row is used.
     */
    private static function businessScoping(): bool
    {
        return config('multi_business.enabled')
            && static::hasBusinessColumn();
    }

    private static function hasBusinessColumn(): bool
    {
        return Schema::hasColumn('settings', 'business_id');
    }

    private static function isPlatformKey(string $key): bool
    {
        return in_array($key, self::PLATFORM_KEYS, true);
    }

    /**
     * The resolved active business id from the request-scoped BusinessContext,
     * or null when tenancy is disabled / no business is active.
     */
    private static function activeBusinessId(): ?int
    {
        if (! static::businessScoping()) {
            return null;
        }

        $context = app()->bound(BusinessContext::class)
            ? app(BusinessContext::class)
            : null;

        return $context?->businessId();
    }

    /**
     * The business that owns an outlet: the outlet's own business when set,
     * otherwise the active business context. Null while tenancy is off.
     */
    private static function businessIdForOutlet(?Outlet $outlet): ?int
    {
        if (! static::businessScoping()) {
            return null;
        }

        if ($outlet && $outlet->business_id) {
            return (int) $outlet->business_id;
        }

        return static::activeBusinessId();
    }

    /**
     * Layered resolution `outlet -> business -> global` (ADR-04 §7). The global
     * row is always the last fallback so platform defaults still inherit.
     */
    private static function resolve(string $key, ?int $businessId, ?int $outletId, $default = null)
    {
        $layers = [];

        if ($outletId !== null) {
            $layers[] = [$businessId, $outletId];
        }
        if ($businessId !== null) {
            $layers[] = [$businessId, null];
        }
        $layers[] = [null, null];

        foreach ($layers as [$business, $outlet]) {
            $query = static::where('key', $key);

            if (Schema::hasColumn('settings', 'outlet_id')) {
                $outlet === null
                    ? $query->whereNull('outlet_id')
                    : $query->where('outlet_id', $outlet);
            }

            if (static::hasBusinessColumn()) {
                $business === null
                    ? $query->whereNull('business_id')
                    : $query->where('business_id', $business);
            }

            $value = $query->value('value');

            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    private static function upsert(string $key, ?int $businessId, ?int $outletId, $value, ?string $description)
    {
        $attributes = ['key' => $key];

        if (Schema::hasColumn('settings', 'outlet_id')) {
            $attributes['outlet_id'] = $outletId;
        }

        if (static::hasBusinessColumn()) {
            $attributes['business_id'] = $businessId;
        }

        return static::updateOrCreate($attributes, [
            'value' => $value,
            'description' => $description,
        ]);
    }

    /**
     * Get a setting value by key. Resolution: business -> global.
     */
    public static function get(string $key, $default = null)
    {
        if (static::isPlatformKey($key) || ! static::businessScoping()) {
            return static::resolve($key, null, null, $default);
        }

        return static::resolve($key, static::activeBusinessId(), null, $default);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) static::get($key, $default);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        return filter_var(static::get($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOL);
    }

    /**
     * Get a setting value for an outlet. Resolution: outlet -> business -> global.
     */
    public static function getForOutlet(string $key, ?Outlet $outlet, $default = null)
    {
        if (! Schema::hasColumn('settings', 'outlet_id')) {
            return static::get($key, $default);
        }

        return static::resolve($key, static::businessIdForOutlet($outlet), $outlet?->id, $default);
    }

    public static function getBoolForOutlet(string $key, ?Outlet $outlet, bool $default = false): bool
    {
        return filter_var(static::getForOutlet($key, $outlet, $default ? '1' : '0'), FILTER_VALIDATE_BOOL);
    }

    /**
     * Set a setting value for an outlet (outlet override layer).
     */
    public static function setForOutlet(string $key, $value, ?Outlet $outlet, ?string $description = null)
    {
        if (! $outlet || ! Schema::hasColumn('settings', 'outlet_id')) {
            return static::set($key, $value, $description);
        }

        return static::upsert(
            $key,
            static::businessIdForOutlet($outlet),
            $outlet->id,
            $value,
            $description
        );
    }

    /**
     * Set a setting value by key. Tenant-owned keys are written to the active
     * business row; platform keys keep using the global row. Writing a
     * tenant-owned key without an active business fails closed.
     */
    public static function set(string $key, $value, ?string $description = null)
    {
        if (static::isPlatformKey($key) || ! static::businessScoping()) {
            return static::upsert($key, null, null, $value, $description);
        }

        $businessId = static::activeBusinessId();

        if ($businessId === null) {
            throw new TenantSettingScopeException(
                "Setting [{$key}] is tenant-scoped and requires an active business context."
            );
        }

        return static::upsert($key, $businessId, null, $value, $description);
    }

    public static function setMany(array $settings): void
    {
        foreach ($settings as $key => $payload) {
            static::set(
                $key,
                $payload['value'] ?? null,
                $payload['description'] ?? null
            );
        }
    }
}
