# Multi-Business — Phase 3 (Build 3) Implementation Notes

Status: implemented on working tree, **not activated in production**.
Feature flag `multi_business.enabled` remains **false** by default, so production
behaviour is unchanged. No migrations, seeders, or backfill `--apply` were run
against any real database in this build.

Build 3 hardens tenant isolation for POS, transactions, and stock, and closes the
route-binding ordering and cross-business-foreign-key gaps flagged as Build 3
prerequisites in `multi-business-phase-2-implementation-notes.md`.

Build 3 does **not** create memberships and does **not** enable tenancy on real
routes. Decisions Q1–Q16 remain unapproved; see section 7.

## 1. Scope of this build

Three security gates plus the POS/transaction/stock isolation work:

- **Gate 1.1 — binding order, fail-closed scope, no context leak.**
- **Gate 1.2 — cross-business foreign-key ownership validation.**
- **Gate 1.3 — backfill hardening (explicit legacy-ownership confirmation).**
- POS/transaction/stock isolation via the warehouse → outlet → business derivation
  plus isolation feature tests.

## 2. Gate 1.1 — middleware ordering, fail-closed scope, no leak

### Files changed

- `bootstrap/app.php`
  - Import `Illuminate\Routing\Middleware\SubstituteBindings`.
  - `$middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveBusinessContext::class)`.
  - `$middleware->appendToPriorityList(after: ResolveBusinessContext::class, append: EnsureActiveBusiness::class)`.
  - Net effect: `ResolveBusinessContext` → `SubstituteBindings` → `EnsureActiveBusiness`,
    so implicit route-model binding resolves **after** the business global scope is
    active, and the required-context gate runs after context resolution.
- `app/Http/Middleware/EnsureActiveBusiness.php` — **flag guard**: no-op when
  `multi_business.enabled` is false; aborts 403 only when the flag is on and no
  context is resolvable. Production (flag off) is unaffected.
- `app/Http/Middleware/ResolveBusinessContext.php` — resolves context from session
  `active_business_id` **or** a stateless API `X-Business-Id` header (verified via
  membership resolver / super-admin active-business check). Query params are never
  trusted.
- `app/Models/Concerns/BelongsToBusiness.php` — global scope is now **fail-closed**:
  flag on + no context → `whereRaw('1 = 0')` (previously fail-open, returned all rows).
- `app/Providers/AppServiceProvider.php` — `scoped(BusinessContext::class)` and a
  `RequestHandled` listener clears `BusinessContext` after every request so context
  cannot leak across queued/adjacent requests.

### Tests

`tests/Feature/Business/TenantRouteBindingTest.php` (3):
own-business binding 200, other-business binding 404, fail-closed without required
context 404.

## 3. Gate 1.2 — cross-business foreign-key ownership

### New components

- `app/Support/Tenancy/TenantOwnership.php` — single source of truth for
  "does entity X belong to the active business". No-op (always passes) when the
  flag is off. Strategy per table:
  - `DIRECT_TABLES` (own `business_id`): `products`, `categories`, `customers`,
    `suppliers`, `units`, `price_lists`, `outlets`.
  - `WAREHOUSE_SCOPED_TABLES` (owned via `warehouse_id` → outlet → business):
    `purchase_orders`, `goods_receivings`, `stock_opnames`, `transactions`,
    `cashier_shifts`, `carts`, `product_batches`, `sales_returns`,
    `supplier_returns`. `stock_mutations` is intentionally excluded (no
    `warehouse_id` column).
  - `OUTLET_SCOPED_TABLES` (owned via `outlet_id`): `bank_accounts`,
    `payment_settings`, `customer_vouchers`, `customer_campaigns`.
  - `warehouses` resolved through `BusinessHierarchyService`.
  - Unknown tables pass (permissive default; the caller must add coverage).
- `app/Rules/BelongsToActiveBusiness.php` — Laravel `ValidationRule` wrapping
  `TenantOwnership::entityBelongsToActiveBusiness()`, so `exists:` checks can be
  made business-aware. Passes unconditionally when the flag is off.

### Rules applied

| Controller / Request | Field(s) |
|----------------------|----------|
| `Apps/ProductController` | `category_id`, `warehouse_id`, `components.*.component_product_id`, `units.*.unit_id` |
| `Api/ProductController` | `category_id`, `warehouse_id` |
| `Api/PosApiController` | open-shift `warehouse_id`; add-to-cart `product_id`, `unit_id`; checkout `customer_id`, `customer_voucher_id`, `bank_account_id`, `tenders.*.bank_account_id` |
| `Apps/TransactionController` | preview-pricing + add-to-cart + store validation (`customer_id`, `customer_voucher_id`, `bank_account_id`, tenders) |
| `Apps/StockTransferController` | `source_warehouse_id`, `destination_warehouse_id`, `items.*.product_id` |
| `Apps/PurchaseOrderController` | `supplier_id`, `warehouse_id`, `items.*.product_id` |
| `Apps/ImportExportController` | `warehouse_id` |
| `Api/WarehouseController` | `outlet_id` |
| `Apps/GoodsReceivingController` | `purchase_order_id` |
| `App\Http\Requests\StoreStockOpnameRequest` | `warehouse_id` |
| `App\Http\Requests\StoreStockOpnameItemRequest` | `product_id` |
| `Apps/PayableController` | `supplier_id`, `bank_account_id` |
| `Apps/ReceivableController` | `customer_id`, `bank_account_id` |

### Middleware mounted on routes

- `routes/api.php` — master-data resources wrapped in
  `Route::middleware(['business', 'business.required'])`; POS group uses
  `['business', 'business.required', 'abilities:pos-access']`.
- `routes/web.php` — dashboard group is `['auth', 'business', 'business.required']`.

Both middlewares are no-ops while the flag is false, so real-route behaviour is
unchanged in production.

### Service-level scoping

- `app/Services/OutletAccessService.php` — `warehousesFor`, `defaultOutlet`, and
  `accessibleOutlets` now apply `restrictToActiveBusiness()` (flag+context gated,
  fail-closed); `canUseWarehouse()` verifies warehouse ownership. The selects were
  widened to include `business_id` on both the warehouse and its eager-loaded
  outlet, fixing a false 403 on the success path.

## 4. Gate 1.3 — backfill hardening

`app/Console/Commands/BusinessBackfillCommand.php`:

- New `--confirm-legacy-ownership` flag. `--apply` now **returns FAILURE** and
  writes nothing unless the flag is supplied. The check runs after the dry-run
  early-return and before any writes, so no-op / multi-business / conflict
  early-returns are unaffected.
- `Business::create` moved **inside** the `DB::transaction` (target passed by
  reference) so a failed mapping rolls back the business row too.
- Redundant inner businesses-table guard removed (already checked earlier).

### Tests

`tests/Feature/Business/BusinessBackfillCommandTest.php` (7): the two apply tests
pass `--confirm-legacy-ownership`; new
`test_apply_without_confirmation_aborts_without_writing` asserts FAILURE and zero
writes (no business, `outlet.business_id`/`product.business_id` stay null).

## 5. Isolation matrix (POS / transaction / stock)

| Surface | Mechanism | Flag off | Flag on, no context | Flag on, own business | Flag on, other business |
|---------|-----------|----------|---------------------|-----------------------|-------------------------|
| Master data (`products` etc.) | `BelongsToBusiness` global scope | all rows | 0 rows (fail-closed) | own rows | hidden |
| Implicit route binding | middleware priority (`ResolveBusinessContext` before `SubstituteBindings`) | unchanged | 404 | own row 200 | 404 |
| `warehouses` for user | `OutletAccessService::restrictToActiveBusiness` | unchanged | none | own | hidden; `canUseWarehouse` false |
| `transactions` / `cashier_shifts` / `stock_opnames` / purchasing | `TenantOwnership` via `warehouse_id` | passes | fails (fail-closed) | own | rejected |
| `bank_accounts` / `payment_settings` | `TenantOwnership` via `outlet_id` | passes | fails (fail-closed) | own | rejected |
| Client-supplied `business_id` | forced from server context (`creating` hook) | ignored/forced | n/a | forced to own | forced to own |
| Query-param `business_id` | never trusted | ignored | ignored | ignored | ignored |

### Tests

- `tests/Feature/Business/TenantRouteBindingTest.php` (3)
- `tests/Feature/Business/CrossBusinessForeignKeyTest.php` (14) — includes
  warehouse-scoped `purchase_orders` ownership, goods-receiving foreign-PO
  rejection, and stock-opname foreign-warehouse rejection.
- `tests/Feature/Business/TransactionStockIsolationTest.php` (5) — `TenantOwnership`
  resolves `transactions`/`stock_opnames`/`cashier_shifts` via warehouse; product
  global scope fails closed with no context and isolates with context.
- `tests/Feature/Business/MasterDataIsolationTest.php` (9) — unchanged from Build 2.

## 6. Test results

Full suite: `php artisan test` → **516 passed (2214 assertions)**.

- Build 2 baseline: 493.
- Build 3 adds 23 tests. All targeted Files and the full suite pass; all edited
  PHP files pass `php -l`; Pint run on every file touched in this build.

## 7. Blockers — decisions still unapproved (Q1–Q16)

All of Q1–Q16 remain formally unapproved and are cited as blockers. The most
relevant to Build 3:

- **Q2** (owner vs super-admin) / **Q3** (role per business) — block membership
  creation; without memberships `business.required` cannot be enabled on real
  routes with real users.
- **Q7** (legacy outlets → one business) / **Q9** (legacy data ownership) — block
  final backfill ownership rules. `--apply` therefore requires explicit
  `--confirm-legacy-ownership`; the command never guesses ownership.
- **Q14** (Spatie teams vs membership) — blocks the final authorization model.
- **Q15** (`warehouses.outlet_id IS NULL`) — central warehouses with no outlet
  cannot prove ownership and therefore fail closed under tenancy.

No legacy ownership was auto-assigned, no owner was guessed, no new role was made
final, and super-admin was not auto-granted Owner.

## 8. Known gaps / future work

- **No web UI business switcher.** There is no route/controller to set
  `active_business_id`. With the flag on, web users rely on sole-membership
  auto-resolve; API clients use `X-Business-ID`.
- **Memberships not seeded.** `business.required` stays inert on real routes.
- **Fail-closed by design.** Rows that cannot prove ownership (legacy rows with
  null `warehouse_id`, central warehouses with null `outlet_id`) are hidden under
  tenancy. This is intentional and ties to Q7/Q9/Q15.
- **`stock_mutations` not covered by `TenantOwnership`** (no `warehouse_id`); it is
  reached only through scoped parents.
- **Other surfaces** (reports, CRM audience, loyalty insights, scheduler, webhooks
  — SEC-04/05/06/12/15/16) were not changed in this build.
- **Rollback** of the Build 2 `business_id` FKs still not exercised on MySQL.
- Flag must remain **false** in production until Q-decisions are approved and
  memberships exist.
