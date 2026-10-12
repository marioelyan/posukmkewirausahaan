# Multi-Business Phase 4A — Reports & Analytics Isolation (Implementation Notes)

Status: **Complete for the report surfaces in scope.** CRM/loyalty/promo *scoping*
inside Insights is handled fail-closed (see §10). Backend/security only — no frontend,
DB, route, or config changes beyond the report controllers and the transaction export.

`MULTI_BUSINESS_ENABLED` default is unchanged
(`config/multi_business.php:16` → `env('MULTI_BUSINESS_ENABLED', false)`). No
migrations, seeders, backfill, or deployment were run.

---

## 1. Report surfaces audited

| Surface | Route | Controller / source | Primary data | Business boundary | Protection before 4A | Risk |
|---|---|---|---|---|---|---|
| Sales report | `reports.sales.index` | `Reports\SalesReportController@index` | `transactions` (+`transaction_details`, `profits`) | warehouse→outlet→business | `whereIn('warehouse_id', warehousesFor($user))`; `customer_id`/`category_id` via tenant-scoped models; `warehouse_id` request filter guarded by `contains` | Low (already scoped) |
| Profit report | `reports.profits.index` | `Reports\ProfitReportController@index` | `transactions`, `profits` | warehouse→outlet→business | same as Sales | Low (already scoped) |
| Insights | `reports.insights.index` | `Reports\AdvancedSalesInsightsController@index` | `transactions`, `transaction_details`, `products`, `customers` | warehouse→outlet→business / direct | transaction + product + customer metrics scoped; **CRM/loyalty/promo panels unscoped** | **High (fixed)** |
| Dashboard analytics | `dashboard` | `DashboardController@index` | `transactions`, `profits`, `transaction_details`, `products` | warehouse→outlet→business | aggregates scoped via `whereIn(warehouse_id)`; **legacy inclusion + checklist existence unscoped** | **Medium (fixed)** |
| Cashier-shift report | `cashier-shifts.report` | `Apps\CashierShiftController@printReport` | `cashier_shifts` (via `{cashierShift}` binding) | warehouse→outlet→business | route-model binding + `TenantOwnership` (Build 3) | Low (out of report scope) |
| Transaction export | `export.transactions` | `Apps\ImportExportController@exportTransactions` → `Exports\TransactionsExport` | `transactions` | warehouse→outlet→business | super-admin `warehouseIds = null` (unrestricted); raw `warehouse_id` request param | **High (fixed)** |
| Product/Customer export | `export.products`, `export.customers` | `Exports\{Products,Customers}Export` | `products`, `customers` | direct `business_id` | model global scope (`BelongsToBusiness`) | None (already scoped) |

No report-related services, repositories, jobs, or API endpoints exist (search
confirmed: no `app/Services` report classes; no `reports` routes in `routes/api.php`).
Web reports are the only surface.

## 2. Ownership paths

- **Direct `business_id`:** `products`, `categories`, `customers`, `suppliers`,
  `units`, `price_lists`, `warehouses`, `outlets` — enforced by
  `BelongsToBusiness` global scope.
- **Warehouse→outlet→business:** `transactions`, `transaction_details`, `profits`,
  `purchase_orders`, etc. No `business_id`; the report derives scope from the
  user's business-restricted warehouse ids (`OutletAccessService::warehousesFor`).
- **Outlet-scoped (via `outlet_id`→`outlets.business_id`):** `pricing_rules`,
  `customer_vouchers`, `customer_campaigns` (and `customer_campaign_logs` transitively
  via campaign), per `multi-business-data-ownership-matrix.md` and
  `TenantOwnership::OUTLET_SCOPED_TABLES`.
- **Child of customer:** `loyalty_point_histories` (via `customer_id`).
- **No owner column:** `customer_segments`, `audit_logs`.

## 3. Vulnerable queries found

1. **`AdvancedSalesInsightsController::promoMonitor()`** —
   `PricingRule::query()` returned *every* pricing rule when no active outlet was
   resolved, and `AuditLog::query()->where('module','pricing_rules')` returned audit
   rows across all businesses. Neither table has `business_id`.
2. **`AdvancedSalesInsightsController::loyaltyPerformance()`** —
   `LoyaltyPointHistory::query()` aggregated points/redemption/voucher amounts for
   *all* customers, and `CustomerVoucher::query()` could load vouchers not owned by
   the active business.
3. **`AdvancedSalesInsightsController::crmOperations()`** —
   `CustomerSegment::query()`, `CustomerCampaign::query()`, and
   `CustomerCampaignLog::query()` counted every business's CRM activity.
4. **`ImportExportController::exportTransactions()`** — for a super-admin
   `$warehouseIds` was `null`, so `TransactionsExport` applied no warehouse filter and
   exported transactions from **all** businesses; `warehouse_id` from the request was
   applied without ownership validation.
5. **`DashboardController::index()`** — `$includeLegacy = Outlet::active()->count() <= 1`
   folded null-warehouse legacy transactions (and null-warehouse product stock) into a
   business's dashboard even while tenancy was enabled; and
   `setupChecklist['transaction'] = Transaction::exists()` performed a global
   (cross-business) existence check.
6. Non-vulnerable but reviewed: the `cashiers` prop in all three controllers is
   `User::select('id','name')->get()` — not business-owned (users belong to
   businesses via membership), so the *filter* cannot leak transactions; see §10/§11.

## 4. Fixes made

**`app/Http/Controllers/Reports/AdvancedSalesInsightsController.php`**
- Added a memoised `activeBusinessOutletIds()` helper: returns `null` when tenancy is
  off (legacy no-op), and the outlet ids of the verified `BusinessContext` when on —
  an **empty** collection when tenancy is on without context, so outlet-scoped queries
  fail closed.
- `include_legacy` is now `false` whenever tenancy is enabled, so unowned
  (null-warehouse) legacy transactions and null-warehouse product stock are never
  folded into a business's report.
- `promoMonitor()`: pricing rules constrained to `outlet_id IN (active business
  outlets)`; `recent_audits` suppressed (empty) while tenancy is enabled (no owner).
- `loyaltyPerformance()`: point-history scoped via `whereHas('customer')` (inherits
  the business-aware `Customer` scope); vouchers constrained to active-business
  outlets.
- `crmOperations()`: campaigns constrained to active-business outlets; campaign logs
  scoped via their campaign's outlet; segments fail closed (no owner column);
  `recent_campaigns` outlet-scoped.

**`app/Http/Controllers/Apps/ImportExportController.php`**
- `exportTransactions()` now always passes a non-null, business-restricted warehouse
  list when tenancy is enabled (super-admin included) and disables legacy inclusion;
  validates `warehouse_id` against the active business via the existing
  `BelongsToActiveBusiness` rule. Legacy mode keeps the historical super-admin bypass.

**`app/Http/Controllers/DashboardController.php`**
- `$includeLegacy` is now `! config('multi_business.enabled') && Outlet::active()->count() <= 1`,
  so null-warehouse legacy transactions and product stock are never merged into a
  business's dashboard while tenancy is enabled (consistent with Insights).
- `setupChecklist['transaction']` is warehouse-scoped while tenancy is enabled; legacy
  mode keeps the original global `Transaction::exists()` behavior.

**`SalesReportController` / `ProfitReportController`** — reviewed, **unchanged**: their
transaction aggregates are already constrained by
`whereIn('warehouse_id', warehousesFor($user))` (business-restricted) and the
`warehouse_id` request filter is already gated by `contains()`. No redundant
conditions were added (per the "do not add blindly" instruction).

## 5. Export surfaces audited

- `Exports\ProductsExport` / `Exports\CustomersExport`: select through models carrying
  the `BelongsToBusiness` global scope → already business-scoped. No change.
- `Exports\TransactionsExport`: takes warehouse ids from the controller; the fix is in
  the controller (above). The export class itself already fails closed for an empty
  warehouse list (`whereRaw('1 = 0')`) when legacy inclusion is off.
- No queued export jobs, streamed custom responses, CSV/PDF report exports, or export
  jobs storing filters exist. `Excel::download` runs synchronously in-request, so there
  is no post-request tenant-context problem to solve. No queue/tenant architecture was
  invented.

## 6. Tests added

`tests/Feature/Business/ReportIsolationTest.php` — **15 tests / 166 assertions**.
Datasets for Business A and Business B share an overlapping timestamp
(`2026-06-15 10:00:00`) to prove isolation is not an artifact of disjoint dates.

| Test | Covers |
|---|---|
| `test_sales_report_only_contains_active_business_data` | 9.1 Sales isolation (A) |
| `test_sales_report_scopes_for_business_b_too` | 9.1 Sales isolation (B) |
| `test_profit_report_only_contains_active_business_data` | 9.2 Profit isolation (revenue/cost/profit/count) |
| `test_insights_metrics_are_business_scoped` | 9.3 Insights: summary, topSelling, marginByCategory, salesByDay, salesByHour, cashierPerformance, repeatCustomerMetrics, stockCoverage |
| `test_insights_crm_loyalty_and_promo_panels_do_not_leak_other_business` | 9.3 promo/loyalty/CRM panels — own data shown, foreign + unowned excluded |
| `test_sales_report_ignores_foreign_warehouse_filter` | 9.4 Warehouse filter isolation |
| `test_profit_report_ignores_foreign_warehouse_filter` | 9.4 Warehouse filter isolation |
| `test_session_active_outlet_from_other_business_cannot_widen_scope` | 9.5 Outlet isolation |
| `test_overlapping_dates_do_not_merge_business_totals` | 9.6 Date-range isolation |
| `test_reports_fail_closed_without_business_context` | 9.7 Fail closed (403) for all three reports |
| `test_warehouse_scope_fails_closed_without_context` | 9.7 Fail closed at the scope level |
| `test_aggregate_totals_exclude_other_business` | 9.8 Aggregate-leak (A=12,345, B=98,765; assert ≠ 111,110) |
| `test_dashboard_analytics_are_business_scoped` | 9.8 Dashboard totals + topProducts + scoped checklist |
| `test_transaction_export_is_business_scoped_for_super_admin` | 9.8 export isolation via `Excel::fake()` |
| `test_legacy_mode_reports_remain_unscoped` | 10 flag-off behavior preserved |

Full suite: **530 passed (2367 assertions)**. Existing `AdvancedSalesInsightsTest`
(flag off) still passes unchanged.

## 7. Flag-off behavior

- All fixes are guarded by `config('multi_business.enabled')`; when the flag is off the
  `activeBusinessOutletIds()` helper returns `null` and every added condition is a
  no-op, `include_legacy` uses the original outlet-count heuristic, and
  `BelongsToActiveBusiness` always passes.
- `test_legacy_mode_reports_remain_unscoped` asserts model global scopes are inert and
  a legacy admin can read data tagged to either business.

## 8. Fail-closed behavior

- With tenancy enabled, the dashboard group requires `business.required`; a request
  with no verified context is rejected (403) before reaching any controller.
- Defensively, an empty `activeBusinessOutletIds()`/warehouse list degrades report
  queries to `whereIn(..., [])` → `0 = 1`, so even a direct call returns no rows.
- `include_legacy` is disabled under tenancy so unowned legacy rows are never included.

## 9. Unresolved legacy ownership

- Transactions/products with no warehouse (legacy) are excluded from reports whenever
  tenancy is on (they cannot be proven to belong to a business). This may make reports
  appear empty on a tenant that still holds unmapped legacy rows — **governed by Q7/Q9**;
  no ownership was invented and no backfill was run.
- Outlet-scoped CRM rows with `outlet_id = NULL` (pricing rules, vouchers, campaigns)
  are treated as unowned and dropped while tenancy is enabled — **Q9/Q15 territory**,
  defaulted to fail closed rather than guessed.

## 10. Report surfaces intentionally deferred

- **CRM / audience / loyalty *scoping semantics*** (per-business audience rules,
  segment ownership, membership-aware visibility) — deferred to Build 4B. Build 4A only
  guarantees no cross-business *exposure* (outlet/customer-derived where possible, fail
  closed otherwise).
- `customer_segments` and `audit_logs` remain unscopable (no owner column); they are
  suppressed under tenancy rather than assigned an owner.
- The `cashiers` filter dropdown (`User::select('id','name')`) in all three report
  controllers is **not** business-scoped: users are not business-owned, and defining
  which users belong to a business is a membership/role decision (**Q2/Q3/Q14**). The
  `cashier_id` filter itself is harmless because the base transaction query is already
  business-scoped (a foreign cashier id yields no rows).

## 11. Remaining Build 4 gaps

- CRM / audience / loyalty insight **scoping** (Build 4B).
- Imports/exports outside the transaction export (`ImportExportController` import paths,
  template download) — not report data.
- REST API report surfaces (none exist today; §1).
- Scheduler/jobs/webhooks for reports (none exist today).
- Membership/role semantics (Q2/Q3) and legacy outlet/warehouse mapping (Q7/Q9/Q15).
- Frontend report UI (untouched by design).

---

### Fixed
Sales/Profit already scoped (verified + tested); Insights transaction/product/customer
metrics (verified + tested); Insights CRM/loyalty/promo cross-business exposure
(fail-closed/scoped); transaction export super-admin bypass and unvalidated warehouse
filter; null-context fail-closed.

### Verified
Flag-off legacy behavior (`AdvancedSalesInsightsTest` + `test_legacy_mode...`);
warehouse/outlet/date filter isolation; aggregate isolation; export isolation.

### Deferred
CRM/audience/loyalty scoping semantics; segment/audit-log ownership; cashier-list
membership scoping; API/jobs/webhook report surfaces.

### Blocked by unresolved product decisions
Q2/Q3/Q14 (membership & roles → cashier list, per-business users), Q7/Q9/Q15 (legacy
outlet/warehouse ownership → null-outlet and null-warehouse rows).
