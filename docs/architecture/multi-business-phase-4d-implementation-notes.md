# Multi-Business Phase 4D — API & Integration Boundary Isolation (Implementation Notes)

Status: **Inventory + minimal hardening complete.** Backend only. No schema, migrations, backfill, feature-flag activation, or deployment. Flag remains disabled.

## 1. Inventory (routes, middleware, auth, bindings)

### API routes & groups
- `routes/api.php`: token-based REST v1 under Sanctum + throttle; protected group uses `auth:sanctum`, `throttle:api`. 
- Master-data under `Route::middleware(['business','business.required'])->group(...)`: `products`, `customers`, `categories`, `warehouses`, `suppliers` (apiResource) with abilities `*-access|create|edit|delete|update`.
- POS under `Route::prefix('pos')->middleware(['business','business.required','abilities:pos-access'])->group(...)`: shift, products/scan, cart, holds, checkout, sync/transactions.
- Webhooks public: `POST /api/webhooks/midtrans`, `POST /api/webhooks/xendit` (no auth; signature-verified in controller).

### Auth
- Sanctum tokens (`auth:sanctum`) for API v1. Token abilities stamped at login from Spatie permissions + `'user:read'`. Public registration token has only `'user:read'` (cannot reach `/pos/*`).
- Web uses session auth + middleware `auth`, `business`, `business.required`, `permission:*`, `active_shift`, etc.

### Business context/tenancy helpers
- Middleware: `ResolveBusinessContext` (sets context from session `active_business_id` or `X-Business-Id` header; superadmin may select any active business; non-superadmin via membership), `EnsureActiveBusiness` (fails closed when flag on and no context; no-op when off).
- `BelongsToBusiness` trait (global scope + creating sets `business_id` from context; fail-closed no context when enabled).
- `BelongsToActiveBusiness` rule (tenant-aware FK validation; resolves via `TenantOwnership`).
- `TenantOwnership` resolves direct/outlet/warehouse ownership; fails closed when enabled without context.
- `TenantUnique` (Build 4C) scopes uniqueness to `business_id` under tenancy.

### Route model binding
- Eloquent implicit binding used for `Customer $customer`, `Product $product`, etc.; controllers call `findOrFail` in some places. Customer/Product use `BelongsToBusiness` global scope → cross-business IDs 404 under tenancy with context.
- TenantRouteBindingTest verifies cross-business substitution rejected.

## 2. Surfaces inspected

**API controllers:** `Api\AuthController`, `Api\CategoryController`, `Api\CustomerController`, `Api\PaymentWebhookController`, `Api\PosApiController`, `Api\ProductController`, `Api\SupplierController`, `Api\WarehouseController`.
**Web controllers (CRM/Loyalty):** `CustomerController`, `MemberController`, `CustomerVoucherController`, `CustomerSegmentController`, `CrmCampaignController`, `CrmReminderController`, `TransactionController`, `SalesReturnController`.
**Models:** Customer (BelongsToBusiness), CustomerVoucher (outlet-scoped), CustomerCampaign (outlet-scoped), CustomerSegment (global), CustomerSegmentMembership (Build 4C scoped via customer), LoyaltyPointHistory, CustomerCredit.
**Jobs/listeners/commands:** `CrmSyncSegmentsCommand`, `CrmGenerateRemindersCommand`, `GenerateReorderCommand`, others; no dedicated integration queue jobs discovered. **No outbound HTTP clients or third-party integration SDK classes found** beyond webhook handlers. Scheduled CRM runs without context (fail-closed by design per 4C).
**Webhook handlers:** `PaymentWebhookController@midtrans`, `@xendit` — verify signatures, lookup local transaction/order, mutate payment status; do **not** trust `business_id` from payload; resolve via transaction/warehouse ownership (existing). No other webhook endpoints.

## 3. Existing enforcement (verified)
- API master-data + POS behind `auth:sanctum` + `business` + `business.required` + abilities.
- POS checkout validates `customer_id`, `customer_voucher_id`, `bank_account_id`, tenders via `BelongsToActiveBusiness`.
- Web checkout same validation (4A).
- Customer global scope blocks cross-business reads under tenancy with context; route binding 404s.
- CustomerSegmentMembership global scope (4C) excludes foreign customers.
- CRM controllers scoped by outlet/active business (4C).
- TenantUnique prevents cross-business duplicate probing (4C).

## 4. Gaps / hardening applied

1. **API Customer uniqueness** (already used `TenantUnique` in web; API `CustomerController` now uses `TenantUnique` for `no_telp` on store/update) — prevents cross-business duplicate detection leakage under tenancy.
2. **Customer history/aggregates** (web) already scoped to active business warehouses (4C). API `CustomerController` does not expose transaction history; returns basic customer resource via `CustomerResource` (scoped customer). 
3. **POS API voucher/customer lookup**: checkout uses `BelongsToActiveBusiness` on IDs (server-side). No change needed.
4. **Webhook boundary**: `PaymentWebhookController` resolves transaction/payment by provider reference; ownership flows via transaction→warehouse→business. No `business_id` taken from webhook payload. Treat as safe by existing design; no code change required (no untrusted business_id consumed).
5. **Raw query / unscoped access audit**: grep showed controlled DB::table usage (outlet cleanup, reports, etc.) — no API endpoint found doing unscoped cross-business reads; report controllers use explicit scoping (4A). No change.
6. **Integration outbound**: none discovered (no external HTTP service wrappers beyond maybe not present). Documented.
7. **Jobs/events**: CRM commands iterate scoped customers (fail-closed no context). No integration jobs. No change.

## 5. Feature flag & compatibility
- `multi_business.enabled` remains `false` by default; all guards `if (! config('multi_business.enabled')) return ...` preserve legacy behavior.
- No `.env`, migrations, backfills, deployments touched.

## 6. Tests
- Business isolation suite: **50 passed (265 assertions)** (CrmLoyaltyIsolationTest 6, ImportExportIsolationTest 15, ReportIsolationTest 17, MasterDataIsolationTest 9, TenantRouteBindingTest 3).
- All existing Business tests green. Pint clean on changed PHP files (TenantUnique, CustomerSegmentMembership previously cleaned; API CustomerController uses TenantUnique).

## 7. Verdict
Definition of done satisfied: API boundaries enforce auth/abilities/context; tenant-aware uniqueness prevents cross-business probing; route binding/ownership enforced; webhooks resolve via trusted relationships; no untrusted `business_id` consumed; background processes fail-closed; legacy preserved; regression baseline green. No REST CRM/Loyalty surface beyond customers exists (customers already constrained). No webhooks beyond payment gateways exist; they don't trust payload business_id. No outbound integrations discovered.

No code changes beyond using existing `TenantUnique` in API CustomerController (already the right boundary) — implementation notes document full inventory and findings.
