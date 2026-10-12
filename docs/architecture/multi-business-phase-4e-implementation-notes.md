# Multi-Business Phase 4E — Authorization & Tenant Boundary Audit (Implementation Notes)

Status: **Audit complete + minimal fail-closed hardening applied.** Backend only. No schema/migration/DB-constraint changes, no backfill/seeder/prod-data changes, no feature-flag activation, no deployment, no frontend/UI changes. `multi_business.enabled` remains `false`.

## 1. Audit method

Build 4E was audit-first, verified against the actual repo (not the docs), and only then followed by minimal edits.

1. **Git baseline** — captured `git status --short`, modified-file list, and diff stat *before* editing. Every prior-build untracked/modified file (4A–4D) was preserved. Pre-existing deletions (`DineAreaController`, `DineOrderController`, `DineTableController`, `DineMenuController`, `docs/features/dine-in.md`) were **not** touched or restored.
2. **Business context resolution** — re-read `ResolveBusinessContext`, `EnsureActiveBusiness`, `BusinessContext` (app `scoped` in `AppServiceProvider`, cleared on `RequestHandled`), `BusinessMembershipResolver`, `bootstrap/app.php` alias/priority (`business`/`business.required` before `SubstituteBindings`).
3. **Entry points** — all web dashboard routes (routes/web.php), REST API v1 + webhooks (routes/api.php), Breeze auth, public share/document routes, console commands (`reorder:generate`, `crm:sync-segments`, `crm:generate-reminders`, `inventory:reconcile`, `outlet:audit`), scheduled jobs. Each was classified as context-aware, context-independent-but-fail-closed, or context-free-by-design.
4. **Authorization + permission + token-ability + ownership consistency** — verified Spatie `permission:` middleware on dashboard routes, Sanctum `abilities:` on `/api/v1/*` and `/pos/*`, `step_up`, `active_shift`, and ownership guards (`canUseWarehouse`, `accessibleOutlets`, `activeBusinessWarehouseIds()`, `BelongsToActiveBusiness`, `TenantOwnership`, `ensureAccess`, `ensureVisible`).
5. **Ownership read/write paths** — traced every write path that accepts a client-supplied relation id (`exists:` rules, implicit route binding, `find()`, `whereKey()`) to see whether the id is later re-resolved against a scoped owner. Findings below record where re-resolution did **not** happen.
6. **Fail-closed behavior** — confirmed `EnsureActiveBusiness` (403 without context when enabled), `BelongsToBusiness` global scope (`1=0` when enabled without context), and `TenantOwnership::entityBelongsToActiveBusiness` (false when enabled without context / unknown ownership).

## 2. Verified context & tenancy facts

- `BusinessContext` is bound `scoped` and cleared on every `RequestHandled`; tests confirm no leakage across requests.
- Session `active_business_id` wins over `X-Business-Id` header (`ResolveBusinessContext`); any active business for super-admins, verified membership for non-super-admins; suspended/archived (non-active) businesses are rejected.
- Master-data models (`Category, Customer, PriceList, Product, Supplier, Unit, Warehouse`) carry the `BelongsToBusiness` global scope. All other tenant-owned models are **unscoped** and are protected per-controller via `TenantOwnership`/`BelongsToActiveBusiness`.
- `pricing_rules` has **no** `business_id` column and **no** global scope; ownership is only via its nullable `outlet_id`. Outlet-less (`outlet_id = null`) pricing rules are **unowned** and were previously reachable from every business (fail-open), and their `exists:products,id` / `exists:categories,id` validations bypassed the product/category global scope.
- `price_lists` does carry `business_id` + global scope, but its `price_list_items.product_id` FK validation was unguarded.
- `payables` / `receivables` carry no ownership column and were absent from `TenantOwnership`; `supplier_returns.payable_id` was validated with a bare `exists:payables,id`.
- `PosApiController::syncTransactions` idempotency did `Transaction::where('client_uuid', $uuid)->first()` with no business filter — a cross-business UUID leak vector (B's invoice/fingerprint echoed to A).
- `CashierShiftService::getActiveShiftForUser` / `requireActiveShiftForUser` did not constrain the open shift to the active business, so a shift opened in business B kept supplying `warehouse_id` to POS cart/checkout after context switched to business A.
- `activeOutlet()` is already safe under tenancy: the shift's eager-loaded `warehouse` relation is subject to the `Warehouse` global scope, so a foreign-business warehouse resolves to `null` and the fallback `accessibleOutlets` restricts to the active business.
- `activeBusinessWarehouseIds()` and the `Warehouse` global scope both filter on `business_id = context`; the documented "null business via outlet" transitional branch is effectively dead because the global scope excludes null-business legacy warehouses. Fail-closed and deliberately kept (no backfill has run).
- Global DB unique indexes exist on `products.barcode`, `products.sku`, `price_lists.slug`, `customer_segments.slug` — tenant-scoped uniqueness would need Q4 + a migration (blocker, unchanged).

## 3. Findings

| # | Severity | Location | Evidence | Impact | Action |
|---|----------|----------|----------|--------|--------|
| F1 | High | `Apps/PricingRuleController` | `store/update` validated `product_id`/`category_id` + `bundle_items.*.product_id`/`buy_get_items.*.product_id` with bare `exists:products,id` / `exists:categories,id`; `canUseRule` returned `true` for `outlet_id === null`; `index`/`summaryBase` listed outlet-less rules | Cross-business product/category FK injection into promotions; unowned outlet-less rules reachable/visible in every business | **Fixed** — added `BelongsToActiveBusiness('products'|'categories')` to all product/category rules; fail-closed null-outlet under tenancy (invisible in list + 404 on edit); legacy mode unchanged |
| F2 | High | `Apps/PriceListController` | `updateItem` validated `product_id` with bare `exists:products,id` | A business-A user could attach business-B's product id to an A price list item (leak limited by global scope on read, but integrity broken) | **Fixed** — added `BelongsToActiveBusiness('products')` |
| F3 | High | `Api/PosApiController::syncTransactions` | `Transaction::where('client_uuid',$uuid)->first()` global; sync item/customer/etc. `exists:` rules unguarded | Cross-business idempotency/disclosure (B's invoice+fingerprint echoed to A); early cross-business FK resolution | **Fixed** — lookup scoped via `findSyncedTransaction()` (restricted to `activeBusinessWarehouseIds()` when enabled; unrestricted in legacy mode) incl. the lost-race lookup; `BelongsToActiveBusiness` added to `customer_id`, `customer_voucher_id`, `bank_account_id`, `items.*.product_id`, `items.*.unit_id` |
| F4 | High | `Apps/SupplierReturnController` + `SupplierReturnService` | `store` validated `supplier_id`, `goods_receiving_id`, `payable_id`, `items.*.product_id` with bare `exists:`; `complete()` mutates `$return->payable` | Cross-business supplier/GR/payable/product FK injection; payable mutation could target a foreign payable (draft created with foreign references) | **Fixed** — `BelongsToActiveBusiness` on all four FKs; `TenantOwnership` extended with `payables → purchase_order` and `receivables → transaction` resolution (manual rows fail closed) |
| F5 | Medium | `Services/CashierShiftService` | `getActiveShiftForUser` / `requireActiveShiftForUser` unscoped; POS cart/checkout use `$shift->warehouse_id` | Active shift from a previous business context continues to be honored; POS operations target another tenant's warehouse id | **Fixed** — `activeShiftQuery()` adds `whereHas('warehouse')` when tenancy enabled (Warehouse global scope fail-closes it: foreign or null warehouse → no shift). Covers `EnsureActiveCashierShift`, POS endpoints, and checkout |
| F6 | Low | `NotificationController::markLowStockRead` | bare `exists:products,id` | read-record could be created for a foreign product | **Fixed** — added `BelongsToActiveBusiness('products')` |
| F7 | Low | `Apps/BankAccountController::updateOrder` | `order.*` bare `exists:bank_accounts,id`; `forOutlet(null)` returns `whereNull('outlet_id')` | Under tenancy with no active outlet, unowned (outlet-less) bank accounts could be reordered; reorder writes sort_order only, no financial impact | **Documented** — fail-open limited to sort-order on unowned rows; reordering is cosmetic. Owner role decide whether outlet-less accounts should exist per business |
| F8 | Blocked (schema) | `Api/ProductController`, `PriceList`, `PriceListController`, `CustomerSegment` | Global DB unique indexes on `products.barcode`, `products.sku`, `price_lists.slug`, `customer_segments.slug`; `TenantUnique` cannot override a DB unique index | Tenant-scoped uniqueness (same barcode/sku/slug in different businesses) requires composite unique indexes | **Deferred** — needs Q4 decision + migration (out of scope) |
| F9 | Blocked (Q5) | `Api/AuthController::me` + `HandleInertiaRequests` store profile | `Setting::get()` returns global store profile / settings shared across businesses | Per-business store profile/settings undefined | **Deferred** — Q5 |
| F10 | Blocked (Q7/Q9/Q15) | `payables`/`receivables` manual rows; `PayableController`/`ReceivableController` statement paths | Manual rows (no `purchase_order_id`/`transaction_id`) have no derivable owner and now fail closed under validation; statement/aggregate paths rely on relation ownership | Manual payables/receivables are invisible/unusable for supplier returns under tenancy until ownership model decided | **Deferred** — Q7/Q9/Q15; not silently re-broken |
| F11 | Info | `customer_segments` | table has no `business_id`/`outlet_id` | `customer_segment_id` on price lists / campaigns cross-business attach is possible; segment ownership undefined | **Deferred** — needs ownership decision (see Q-decision doc) |
| F12 | Info | Console/scheduled commands | `reorder:generate`, `crm:sync-segments`, `crm:generate-reminders` run without tenant context | Correctly fail closed; per-business scheduling needs a dedicated tenancy/jobs build | **Documented** — next build (Jobs/Scheduler), must not be enabled before |

## 4. Changes applied (this build)

All are minimal, additive, legacy-compatible (no-op while `multi_business.enabled = false`), and were written to fail closed under tenancy.

1. `app/Support/Tenancy/TenantOwnership.php` — added `VIA_PARENT_TABLES` (`payables → purchase_orders.purchase_order_id`, `receivables → transactions.transaction_id`). Missing parent link ⇒ ownership unprovable ⇒ `false` when enabled (fail closed); `true` when disabled (legacy).
2. `app/Http/Controllers/Apps/PricingRuleController.php` —
   - FK rules now `BelongsToActiveBusiness('products'|'categories')` (store/update: `product_id`, `category_id`, `bundle_items.*.product_id`, `buy_get_items.*.product_id`).
   - `canUseRule` fails closed on outlet-less rules when tenancy is enabled: `outlet !== null && rule.outlet_id !== null && rule.outlet_id === outlet.id`.
   - `scopeToActiveOutlet()` (used by `index` and `summaryBase`) excludes outlet-less rules when tenancy is enabled and requires a resolved active outlet (`1=0` otherwise). Legacy query (null-outlet = global) preserved when disabled. Server still assigns `outlet_id` from `activeOutlet()` (never client input).
3. `app/Http/Controllers/Apps/PriceListController.php` — `updateItem` `product_id` validated with `BelongsToActiveBusiness('products')`. (`PriceList` itself is already business-scoped; `slug` uniqueness remains a global DB index → F8.)
4. `app/Http/Controllers/Api/PosApiController.php` — `syncTransactions` validation extended with `BelongsToActiveBusiness` on `customer_id`, `customer_voucher_id`, `bank_account_id`, `items.*.product_id`, `items.*.unit_id`; idempotency pre-check and lost-race lookup both routed through `findSyncedTransaction()`, which restricts the `client_uuid` search to the active business's warehouse ids when tenancy is enabled.
5. `app/Http/Controllers/Apps/SupplierReturnController.php` — `store` rules now `BelongsToActiveBusiness` for `supplier_id`, `goods_receiving_id`, `payable_id`, `items.*.product_id`. `payable_id` resolves via the new payables→PO ownership path.
6. `app/Services/CashierShiftService.php` — `getActiveShiftForUser` / `requireActiveShiftForUser` share `activeShiftQuery()` which adds `whereHas('warehouse')` when tenancy is enabled (open shift constrained to the active business; fail-closed without context).
7. `app/Http/Controllers/NotificationController.php` — `markLowStockRead` `product_id` validated with `BelongsToActiveBusiness('products')`.

Reasoning: every fix re-uses the existing `TenantOwnership`/`BelongsToActiveBusiness`/global-scope machinery instead of inventing new access-control logic, so under the `false` flag behavior is byte-for-byte legacy-compatible, and under the flag all new paths rely on the same verified server-side context.

## 5. Tests

### New: `tests/Feature/Business/AuthorizationBoundaryIsolationTest.php` (26 tests)
Covers the 12 required scenarios plus every fix above:

- **Pricing rules**: foreign product target rejected, foreign category target rejected, bundle foreign product rejected, own product accepted (rows persisted in DB with correct `outlet_id`), outlet-less rule 404 under tenancy, other-outlet rule 404.
- **Price list items**: foreign product rejected (no row), own product accepted (row persisted).
- **Supplier returns**: foreign supplier / product / goods-receiving / payable all rejected **and no row persisted**; own payable (owned via own PO warehouse) accepted and persisted.
- **TenantOwnership unit checks**: payables resolved via `purchase_order_id` (own=true, foreign=false, manual=false), receivables via `transaction_id` (own=true, foreign=false, manual=false).
- **Offline sync**: a business-B transaction carrying the same `client_uuid` is **not** echoed as duplicate/invoice/transaction_id to business A.
- **Active shift**: open shifts in both A and B resolve to the shift of the active business only; no context + tenancy on ⇒ no active shift.
- **Auth/token/context**: no token ⇒ 401; token without `pos-access` ⇒ 403; non-super member positive (own business via header) and negative (other business ⇒ 403); suspended business ⇒ 403; session beats header; context reset across four successive requests (session cleared ⇒ 403, clean header switch, header flushed ⇒ 403 again); flag off ⇒ legacy context-free 200.

### Commands run (all real, all passing)
- `php artisan test tests/Feature/Business/AuthorizationBoundaryIsolationTest.php --compact` → 26 passed.
- `php artisan test tests/Feature/Business --compact` → **106 passed (406 assertions)** (baseline 80 passed / 346, prior-regression intact).
- `php artisan test tests/Feature/Api tests/Feature/Auth tests/Feature/Authorization tests/Feature/Security tests/Feature/Pricing tests/Feature/Purchasing tests/Feature/Transactions --compact` → **218 passed (895 assertions)**.
- `php artisan test --compact` → **580 passed (2517 assertions)**.
- `vendor/bin/pint` on all changed PHP (incl. the new test) → auto-fixed `PricingRuleController` import/format only (no logic change); lint re-checked; pricing + business suites re-run green after format.

## 6. Feature flag & compatibility

- `MULTI_BUSINESS_ENABLED` / `config/multi_business.php` untouched — flag remains `false` (legacy single-tenant mode).
- Every new rule is silent in legacy mode (rules short-circuit in `TenantOwnership::entityBelongsToActiveBusiness`, `activeBusinessWarehouseIds()`, `canUseRule`, `canUseList` legacy branches, no `whereHas` on shift without flag).
- New columns/tables: none. No migration, seeder, or backfill changes.

## 7. Deferred decisions (blockers kept open — no silent re-resolution)

- **Q2 / Q3 / Q14** — membership/role/authorization model. Held.
- **Q7 / Q9 / Q15** — legacy ownership for manual payables/receivables and outlet-less records. Held. Manual payables/receivables now fail closed under tenancy (they cannot prove ownership) rather than being guessed.
- **Q4** — tenant-scoped uniqueness (barcode/sku/slug) needs a migration; unchanged. Global unique indexes remain authoritative.
- **Q5** — per-business store profile/settings; unchanged.
- **Q10+ (customer_segments ownership)** — segments remain globally "unowned"; scope decisions deferred (see `multi-business-open-decisions.md`).

## 8. Residual risks & prerequisites

- Manual (PO-less / transaction-less) payables and receivables are the only rows that now fail closed; they become usable again only once Q7/Q9/Q15 defines their ownership.
- Outlet-less `bank_accounts` sort-order reordering (F7) is still technically reachable; cosmetic only.
- Jobs/scheduler build must not be started before per-business context is designed (F12).
- Transitional "null `business_id` warehouse via outlet" ownership branches remain effectively dead behind the `Warehouse` global scope; re-visit only when the `business:backfill` step is approved (out of scope).
- Do **not** enable `multi_business.enabled` or begin Build 5 / any AI build until the open decisions above are approved.