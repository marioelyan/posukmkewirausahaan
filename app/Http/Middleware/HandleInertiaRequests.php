<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\CashierShift;
use App\Models\Outlet;
use App\Models\Payable;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Receivable;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BusinessContext;
use App\Services\BusinessMembershipResolver;
use App\Services\CashierShiftService;
use App\Services\OutletAccessService;
use App\Services\PayableAgingService;
use App\Services\ReceivableService;
use App\Support\ProductionSecurityBaseline;
use App\Support\Tenancy\BusinessRoleCapabilities;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Layout role reported for the platform super-admin, who has no business
     * membership and keeps the explicit platform path (AUTH-01).
     */
    private const ROLE_PLATFORM_ADMIN = 'super-admin';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $lowStockNotifications = [];
        $expiringBatchNotifications = [];
        $receivableNotifications = [];
        $payableNotifications = [];
        $activeCashierShift = null;
        $securityWarnings = [];
        $stepUpFreshUntil = null;
        $payableAgingSummary = null;
        $receivableAgingSummary = null;
        $pendingApprovalCount = 0;
        $activeShift = null;
        $activeOutlet = null;
        $availableOutlets = collect();

        $businessSwitcher = null;
        $businessSwitchLocked = false;
        if ($request->user() && config('multi_business.enabled')) {
            $current = app(BusinessContext::class)->business();
            $accessible = $request->user()->isSuperAdmin()
                ? Business::query()->active()->orderBy('name')->get()
                : app(BusinessMembershipResolver::class)->activeMembershipsFor($request->user())
                    ->pluck('business')
                    ->filter()
                    ->values();

            // While a shift is open the active business is pinned to the shift's
            // business (switching away is rejected with 422 server-side). The
            // dropdown has to reflect that, otherwise it looks selectable while
            // every option except the current one fails. Deliberately
            // cross-business: a shift that lives in another business still
            // means "you are on duty", and `outletLocked` only sees the shifts
            // of the *current* business.
            $businessSwitchLocked = CashierShift::query()
                ->withoutGlobalScope('business')
                ->open()
                ->where('user_id', $request->user()->id)
                ->exists();

            $businessSwitcher = [
                'current' => $current?->only(['id', 'name', 'slug']),
                'accessible' => $accessible->map(fn (Business $b) => $b->only(['id', 'name', 'slug']))->values(),
            ];
        }

        if ($request->user()) {
            $userId = $request->user()->id;
            $outletAccess = app(OutletAccessService::class);
            $warehouseIds = $outletAccess->warehousesFor($request->user())->pluck('id');
            $includeLegacy = Outlet::active()->count() <= 1;

            if ($request->user()->can('discounts-approve')) {
                $pendingApprovalCount = Transaction::query()
                    ->where('discount_approval_status', 'pending')
                    ->where(function ($query) use ($warehouseIds, $includeLegacy) {
                        if ($warehouseIds->isEmpty()) {
                            $query->whereRaw('1 = 0');
                        } else {
                            $query->whereIn('warehouse_id', $warehouseIds);
                            if ($includeLegacy) {
                                $query->orWhereNull('warehouse_id');
                            }
                        }
                    })
                    ->count();
            }

            $lowStockNotifications = Product::query()
                ->leftJoinSub(
                    DB::table('product_warehouse')
                        ->select('product_id', DB::raw('SUM(stock) as warehouse_stock'))
                        ->whereIn('warehouse_id', $warehouseIds)
                        ->groupBy('product_id'),
                    'warehouse_totals',
                    fn ($join) => $join->on('warehouse_totals.product_id', '=', 'products.id')
                )
                ->where('min_stock', '>', 0)
                ->where(function ($query) use ($includeLegacy) {
                    $query->whereColumn('warehouse_totals.warehouse_stock', '<=', 'min_stock');
                    if ($includeLegacy) {
                        $query->orWhere(function ($legacy) {
                            $legacy->whereNull('warehouse_totals.warehouse_stock')
                                ->whereColumn('stock', '<=', 'min_stock');
                        });
                    }
                })
                ->whereNotExists(function ($query) use ($userId) {
                    $query->selectRaw('1')
                        ->from('product_notification_reads as pr')
                        ->whereColumn('pr.product_id', 'products.id')
                        ->where('pr.user_id', $userId)
                        ->whereColumn('pr.updated_at', '>=', 'products.updated_at');
                })
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get(['products.id', 'title', DB::raw('COALESCE(warehouse_totals.warehouse_stock, products.stock) as warehouse_stock'), 'updated_at'])
                ->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'title' => $product->title,
                        'stock' => (int) $product->warehouse_stock,
                        'time' => optional($product->updated_at)->diffForHumans(),
                    ];
                });

            $expiringBatchNotifications = ProductBatch::with('product:id,title')
                ->whereIn('warehouse_id', $warehouseIds)
                ->where('stock', '>', 0)
                ->whereNotNull('expired_at')
                ->whereBetween('expired_at', [now(), now()->addDays(30)])
                ->orderBy('expired_at')
                ->limit(10)
                ->get()
                ->map(function ($batch) {
                    return [
                        'id' => $batch->id,
                        'title' => $batch->product?->title,
                        'batch_number' => $batch->batch_number,
                        'stock' => (int) $batch->stock,
                        'time' => optional($batch->expired_at)->diffForHumans(),
                    ];
                });

            $payableAgingService = new PayableAgingService;
            $receivableService = new ReceivableService;

            $payableAgingSummary = $payableAgingService->getAgingSummary($warehouseIds);
            $receivableAgingSummary = $receivableService->getAgingSummary($warehouseIds);

            $receivableNotifications = $receivableService->scopeQuery(Receivable::query(), $warehouseIds)
                ->whereNot('status', 'paid')
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<=', now()->addDays(3))
                ->orderBy('due_date')
                ->limit(5)
                ->get(['id', 'invoice', 'customer_id', 'due_date', 'total', 'paid', 'status'])
                ->map(function ($item) {
                    $remaining = max(0, ($item->total ?? 0) - ($item->paid ?? 0));

                    return [
                        'id' => $item->id,
                        'title' => "Piutang: {$item->invoice}",
                        'subtitle' => 'Sisa '.number_format($remaining, 0, ',', '.'),
                        'time' => optional($item->due_date)->diffForHumans(),
                        'status' => $item->status,
                        'aging_bucket' => $item->aging_bucket,
                    ];
                });

            $payableNotifications = $payableAgingService->scopeQuery(Payable::query(), $warehouseIds)
                ->whereNot('status', 'paid')
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<=', now()->addDays(3))
                ->orderBy('due_date')
                ->limit(5)
                ->get(['id', 'document_number', 'due_date', 'total', 'paid', 'status'])
                ->map(function ($item) {
                    $remaining = max(0, ($item->total ?? 0) - ($item->paid ?? 0));

                    return [
                        'id' => $item->id,
                        'title' => "Hutang: {$item->document_number}",
                        'subtitle' => 'Sisa '.number_format($remaining, 0, ',', '.'),
                        'time' => optional($item->due_date)->diffForHumans(),
                        'status' => $item->status,
                        'aging_bucket' => $item->aging_bucket,
                    ];
                });

            $activeShift = CashierShift::query()
                ->with('user:id,name', 'warehouse:id,code,name,outlet_id', 'warehouse.outlet')
                ->open()
                ->where('user_id', $userId)
                ->latest('opened_at')
                ->first();

            $activeOutlet = $outletAccess->activeOutlet($request);
            $availableOutlets = $outletAccess->accessibleOutlets($request->user());

            if ($activeShift) {
                $activeCashierShift = app(CashierShiftService::class)->summarizeForDisplay($activeShift);
            }

            $securityWarnings = ProductionSecurityBaseline::issues();

            $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
            if ($confirmedAt > 0) {
                $stepUpFreshUntil = now()
                    ->setTimestamp($confirmedAt + (int) config('auth.password_timeout', 900))
                    ->toISOString();
            }
        }

        $storeProfile = [
            'name' => 'Toko Anda',
            'logo' => null,
            'address' => '',
            'phone' => '',
            'email' => '',
            'website' => '',
            'city' => '',
        ];
        $outlet = $activeShift?->warehouse?->outlet;
        if (! $outlet && $request->user()) {
            $outlet = app(OutletAccessService::class)->activeOutlet($request);
        }

        if (Schema::hasTable('settings')) {
            $logo = Setting::getForOutlet('store_logo', $outlet);
            if ($logo && ! str_starts_with($logo, 'http') && ! str_starts_with($logo, '/storage')) {
                $logo = asset('storage/'.ltrim($logo, '/'));
            }

            $storeProfile = [
                'name' => Setting::getForOutlet('store_name', $outlet, 'Toko Anda'),
                'logo' => $logo,
                'address' => Setting::getForOutlet('store_address', $outlet, ''),
                'phone' => Setting::getForOutlet('store_phone', $outlet, ''),
                'email' => Setting::getForOutlet('store_email', $outlet, ''),
                'website' => Setting::getForOutlet('store_website', $outlet, ''),
                'city' => Setting::getForOutlet('store_city', $outlet, ''),
            ];

            $printerSettings = [
                'autoPrint' => Setting::getBoolForOutlet('printer_auto_print', $outlet, false),
                'paperSize' => Setting::getForOutlet('printer_paper_size', $outlet, '80mm'),
            ];
        } else {
            $printerSettings = [
                'autoPrint' => false,
                'paperSize' => '80mm',
            ];
        }

        $authorization = $this->resolveAuthorization($request);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'permissions' => $authorization['permissions'],
                'businessRole' => $authorization['role'],
                'super' => $request->user() ? $request->user()->isSuperAdmin() : false,
                'completedTours' => $request->user()?->completed_tours ?? [],
                'currentOutlet' => $activeOutlet?->only(['id', 'code', 'name']),
                'outlets' => $availableOutlets->map(fn (Outlet $outlet) => $outlet->only(['id', 'code', 'name']))->values(),
                'outletLocked' => (bool) $activeShift,
                ...($businessSwitcher ? [
                    'currentBusiness' => $businessSwitcher['current'],
                    'businesses' => $businessSwitcher['accessible'],
                    'businessSwitchLocked' => $businessSwitchLocked,
                ] : []),
            ],
            'locale' => [
                'current' => app()->getLocale(),
                'available' => ['id', 'en'],
                'names' => [
                    'id' => 'Indonesia',
                    'en' => 'English',
                ],
            ],
            'lowStockNotifications' => $lowStockNotifications,
            'expiringBatchNotifications' => $expiringBatchNotifications,
            'receivableNotifications' => $receivableNotifications,
            'payableNotifications' => $payableNotifications,
            'payableAgingSummary' => $payableAgingSummary,
            'receivableAgingSummary' => $receivableAgingSummary,
            'activeCashierShift' => $activeCashierShift,
            'storeProfile' => $storeProfile,
            'printerSettings' => $printerSettings,
            'pendingApprovalCount' => $pendingApprovalCount,
            'appVersion' => config('app.version'),
            'multiBusinessEnabled' => (bool) config('multi_business.enabled'),
            'security' => [
                'warnings' => $securityWarnings,
                'publicRegistrationEnabled' => config('security.auth.public_registration'),
                'stepUpFreshUntil' => $stepUpFreshUntil,
            ],
        ];
    }

    /**
     * Resolve the authorization payload the UI gates and lays itself out on.
     *
     * Two values are derived here, from the SAME source on purpose so they can
     * never drift apart:
     *
     *  - `permissions` — the capability map (already used by the menu);
     *  - `role`        — the sidebar's layout role, one of
     *                    `owner|manager|staff`, `super-admin`, or `null`.
     *
     * With tenancy enabled the server enforces the ACTIVE membership's business
     * role (AUTH-02/03 via the `business.role` middleware), so both values must
     * match it — otherwise the menu would offer actions that 403. A non-super
     * member therefore sees only their role's capabilities and layout.
     * Super-admins keep the explicit platform path (AUTH-01) and the flag-off
     * path keeps the legacy global map, with a cosmetic role derived from the
     * Spatie role so the role-aware sidebar works before tenancy is switched on.
     *
     * @return array{permissions: array<string, true>|Collection, role: ?string}
     */
    private function resolveAuthorization(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return ['permissions' => [], 'role' => null];
        }

        // Platform super-admin: global grants, platform-only layout.
        if ($user->isSuperAdmin()) {
            return [
                'permissions' => $user->getPermissions(),
                'role' => self::ROLE_PLATFORM_ADMIN,
            ];
        }

        if (config('multi_business.enabled')) {
            $context = app(BusinessContext::class);
            $membership = $context->hasActiveBusiness() ? $context->membership() : null;

            if (! $membership) {
                // Tenancy enabled without a verified membership: fail closed,
                // the same way the server-side capability check does.
                return ['permissions' => [], 'role' => null];
            }

            return [
                'permissions' => collect(app(BusinessRoleCapabilities::class)->permissionsFor($membership->role))
                    ->mapWithKeys(fn (string $name) => [$name => true]),
                'role' => $membership->role,
            ];
        }

        // Legacy single-tenant install: the global map wins and the layout role
        // falls back to the user's Spatie role.
        return [
            'permissions' => $user->getPermissions(),
            'role' => $this->legacyLayoutRole($user),
        ];
    }

    /**
     * Cosmetic Spatie-role → layout-role mapping for the tenancy-off install.
     *
     * Purely presentational: it only decides how the sidebar is arranged and
     * never widens or narrows a capability. Unknown roles map to `null`, which
     * keeps the menu capability-driven exactly as before.
     */
    private function legacyLayoutRole(User $user): ?string
    {
        foreach ($user->getRoleNames() as $name) {
            $mapped = match ($name) {
                'manager' => BusinessUser::ROLE_MANAGER,
                'cashier' => BusinessUser::ROLE_STAFF,
                'owner' => BusinessUser::ROLE_OWNER,
                default => null,
            };

            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }
}
