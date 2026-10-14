# Multi-Business — Phase 2 (Build 2) Implementation Notes

Status: implemented on branch working tree, **not activated in production**.
Feature flag `multi_business.enabled` defaults to **false**, so production behaviour
is identical to before this build.

Build 2 covers: safe/auditable business backfill, Business → Outlet → Warehouse
hierarchy validation, master-data isolation by active business, guarded controller
checks, feature tests, and this document. It does **not** activate tenancy on real
routes and does **not** create memberships (decisions Q2/Q3/Q7/Q9 still unapproved).

## 1. Feature flag

`config/multi_business.php`

| Key | Env | Default | Effect |
|-----|-----|---------|--------|
| `enabled` | `MULTI_BUSINESS_ENABLED` | `false` | Master switch. When false the `BelongsToBusiness` global scope and `creating` hook are inert. |
| `default_name` | `MULTI_BUSINESS_DEFAULT_NAME` | `Toko Utama` | Fallback business name for `business:backfill --create-default`. |

## 2. Files added

- `database/migrations/2026_10_10_160000_add_business_id_to_master_data_tables.php`
  Adds nullable `business_id` FK (`nullOnDelete`) + index to `products`,
  `categories`, `customers`, `suppliers`, `units`, `price_lists`, `warehouses`.
  Idempotent per table (`Schema::hasTable` / `hasColumn`). `down()` drops
  FK/index/column. **Not executed against any dev/prod database in this build.**
- `config/multi_business.php` — feature flag + default name.
- `app/Models/Concerns/BelongsToBusiness.php` — trait:
  - global scope `business` (no-op unless flag true **and** active context);
  - `creating` hook forces `business_id` from server context (never request payload);
  - `business(): BelongsTo`, `scopeForBusiness()`.
- `app/Services/BusinessHierarchyService.php` — `outletBelongsToBusiness`,
  `warehouseBelongsToBusiness` (direct `business_id`, then outlet chain),
  `outletIdBelongsToBusiness`, `warehouseIdBelongsToBusiness`,
  `outletsForBusiness`, `warehousesForBusiness`.
- `app/Console/Commands/BusinessBackfillCommand.php` — `business:backfill`.

## 3. Files changed

- Models: `Product`, `Category`, `Customer`, `Supplier`, `Unit`, `PriceList`,
  `Warehouse` — apply `BelongsToBusiness`, add `business_id` to `$fillable`/casts.
- `app/Http/Controllers/Apps/WarehouseController.php` — guarded checks that the
  target outlet/warehouse belongs to the active business (only when flag on and
  context present).
- `app/Http/Controllers/Apps/OutletController.php` — sets `business_id` from
  context on create; `ensureAccess()` also checks hierarchy when flag on.

## 4. Master-data ownership matrix (this build)

| Table | Column | Strategy |
|-------|--------|----------|
| `products` | `business_id` | BUSINESS |
| `categories` | `business_id` | BUSINESS |
| `customers` | `business_id` | BUSINESS |
| `suppliers` | `business_id` | BUSINESS |
| `units` | `business_id` | BUSINESS |
| `price_lists` | `business_id` | BUSINESS |
| `warehouses` | `business_id` | BUSINESS (with outlet fallback) |
| `outlets` | `business_id` | BUSINESS (added in Build 1) |

Global unique constraints (e.g. `products.barcode`, `units.code`) are intentionally
left unchanged in this build.

## 5. Backfill mechanism

Command: `php artisan business:backfill [--apply] [--business=ID] [--create-default] [--name="..."]`

- **Dry-run by default.** Read-only; prints per-table `Pending` / `Already` /
  `Conflict` counts and the number of legacy warehouses without an outlet.
- Target resolution:
  - `--business=ID` → must exist, else failure;
  - 0 businesses → requires `--create-default`, else no-op success;
  - 1 business → auto-selected;
  - ≥2 businesses → aborts (never guesses ownership).
- **Aborts on any conflict** (row already owned by another business) and writes
  nothing — no partial migration.
- `--apply` wraps all updates in `DB::transaction`; sets `business_id` on
  unowned rows for outlets, warehouses, and business-owned master tables.
  Idempotent: re-running reports 0 pending and makes no changes.
- Creates a business only with `--create-default --apply` (name from `--name`,
  then `Setting::get('store_name')`, then `config('multi_business.default_name')`).
- **Does not create memberships.**

## 6. Tests

Added under `tests/Feature/Business/`:

- `BusinessBackfillCommandTest` (6): dry-run writes nothing, apply maps rows +
  creates default business, idempotency, multi-business ambiguity abort,
  conflict abort, no-business no-op.
- `BusinessHierarchyServiceTest` (4): outlet ownership, unowned outlet, warehouse
  outlet-chain fallback, direct warehouse `business_id` precedence.
- `MasterDataIsolationTest` (9): index/detail/delete isolation, payload
  `business_id` spoof override, query-param `business_id` ignored, no-membership
  and suspended-membership 403, multi-membership requires explicit active
  business, feature-flag-off legacy visibility.

Result: `php artisan test` → **493 passed (2160 assertions)**. Build 1 baseline was
474; Build 2 adds 19.

## 7. Known gaps / Build 3 prerequisites

- **Route-model-binding ordering.** `SubstituteBindings` is part of the `web`
  middleware group and runs before appended/route-level `business` middleware.
  Therefore implicit route-model binding currently resolves rows *before* the
  business global scope is active. To scope bindings, `ResolveBusinessContext`
  must run after `StartSession` but **before** `SubstituteBindings` (insert into
  the web group between them, or move `SubstituteBindings` after business
  middleware). Isolation tests exercise scoped queries (`findOrFail`) rather than
  implicit binding until that ordering change lands.
- **Memberships are not seeded.** `EnsureActiveBusiness` (business.required)
  cannot be mounted on real routes until users have memberships (Q2/Q3).
- **Not activated.** Flag stays false; no middleware mounted on real routes; no
  route file changes.
- **Cross-business FK values.** Controllers that validate related IDs with
  `exists:` rules (e.g. `ProductController` `category_id`) bypass model scopes and
  still need hierarchy/ownership validation; only Warehouse/Outlet are guarded so
  far.
- **Backfill creates no memberships** and leaves `outlets.business_id` nullable.
- Rollback (`down()`) of the new FK has not been exercised on MySQL.
