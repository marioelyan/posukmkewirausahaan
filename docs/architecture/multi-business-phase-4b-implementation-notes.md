# Multi-Business Phase 4B — Import / Export Isolation (Implementation Notes)

Status: **Complete for the import/export surfaces in scope.** Backend/security
only — no frontend, DB schema, route, or config changes. All fixes are guarded
by `config('multi_business.enabled')` and are inert in legacy mode.

`MULTI_BUSINESS_ENABLED` default is unchanged
(`config/multi_business.php:16` → `env('MULTI_BUSINESS_ENABLED', false)`). No
migrations, seeders, backfill, or deployment were run. No `.env` file was
touched.

---

## 1. Import / Export surfaces audited

A repository-wide search was run for `Excel`, `Maatwebsite`, `CSV`, `XLSX`,
`FromCollection`, `ToModel`, `WithHeadingRow`, `WithBatchInserts`, `ShouldQueue`,
`streamDownload`, `response()->download`, `fputcsv`, `Storage::` and file
parsing. The complete set of import/export surfaces is small and centralized.

| Surface | Route | Type | Controller / class | Primary data | Ownership path | Protection before 4B | Risk |
|---|---|---|---|---|---|---|---|
| Export products | `GET /dashboard/export/products` (`export.products`) | Read | `ImportExportController@exportProducts` → `Exports\ProductsExport` | `products` (+ `categories` relation) | direct `business_id` | `BelongsToBusiness` global scope; route group `business.required` | Low (verified) |
| Export customers | `GET /dashboard/export/customers` (`export.customers`) | Read | `ImportExportController@exportCustomers` → `Exports\CustomersExport` | `customers` | direct `business_id` | same | Low (verified) |
| Export transactions | `GET /dashboard/export/transactions` (`export.transactions`) | Read | `ImportExportController@exportTransactions` → `Exports\TransactionsExport` | `transactions`, `transaction_tenders`, `customers`, `users` | warehouse→outlet→business | 4A: warehouse list always business-restricted; `warehouse_id` filter validated by `BelongsToActiveBusiness`; `includeLegacy=false` | Low (fixed in 4A, re-verified) |
| Import products | `POST /dashboard/import/products` (`import.products`) | Mixed (upsert) | `ImportExportController@importProducts` → `Imports\ProductsImport` | `products`, `categories`, `product_warehouse`, `stock_mutations` | direct `business_id` + warehouse→outlet→business | warehouse validated; scoped `updateOrCreate`; `BelongsToBusiness::creating` set ownership — **no explicit fail-closed guard** | **Medium (fixed)** |
| Import customers | `POST /dashboard/import/customers` (`import.customers`) | Write | `ImportExportController@importCustomers` → `Imports\CustomersImport` | `customers` | direct `business_id` | **none effective** — `WithBatchInserts` → `ModelManager::massFlush()` → raw `insert()` bypassed the `creating` event, so `business_id` was never assigned | **High (fixed)** |
| Template download | `GET /dashboard/import/template/{type}` (`import.template`) | Read (static) | `ImportExportController@downloadTemplate` (anonymous `FromArray`) | none (headings only) | n/a | `match` whitelist, `abort(404)` otherwise | None |
| PDF documents | `GET /dashboard/documents/**` (`pdf.*`) | Read/download | `DocumentController` | transactions / receivables / payables | warehouse→outlet→business | `ensureWarehouseAccess()` → `OutletAccessService::canUseWarehouse()` (business-aware) + route-model binding | Low (verified, adjacent) |
| Public invoice share | `GET /share/transactions/{invoice}?token=` | Read | `DocumentController@publicInvoice` | transaction | `access_token` + invoice | token match, `no-store` | Low (out of scope) |

**No** queued import/export classes, **no** stored export files, **no**
API import/export endpoints (`routes/api.php` has none), and **no** other
file-parsing surfaces exist. `app/Imports` and `app/Exports` contain exactly the
five classes above.

---

## 2. Export ownership paths

- **Direct `business_id`:** `products`, `customers`, `categories` — enforced by
  the `BelongsToBusiness` global scope (`app/Models/Concerns/BelongsToBusiness.php`),
  which filters to the active business and degrades to `1 = 0` when tenancy is
  enabled without context.
- **Warehouse→outlet→business:** `transactions` (and its `tenders`). The export
  derives the allowed warehouse ids from
  `OutletAccessService::warehousesFor()`, which is constrained by
  `restrictToActiveBusiness()`. `includeLegacy` is forced `false` while tenancy
  is enabled so null-warehouse legacy rows are never folded in.
- **Eager-loaded relations:** `customer:id,name` resolves through the
  tenant-scoped `Customer` model; a transaction pointing at a foreign customer
  renders as `Umum` rather than leaking the name. `cashier` is a `User` and is
  not business-owned.

---

## 3. Import ownership paths

- **`ProductsImport`:** `Category::firstOrCreate(['name' => …])` and
  `Product::updateOrCreate(['barcode' => …])` run through tenant-scoped models;
  `BelongsToBusiness::creating` forces `business_id` from the server context.
  4B makes this explicit (see §6) and adds a fail-closed guard.
- **`CustomersImport`:** each row becomes `new Customer([...])`. Because the
  class implements `WithBatchInserts` (batch size 100),
  `ModelManager::flush($import, true)` calls `massFlush()`, which performs a raw
  `Customer::query()->insert($models->toArray())`. **This bypasses Eloquent
  events entirely**, so `BelongsToBusiness::creating` never ran and imported
  customers were written with `business_id = NULL` under tenancy. 4B assigns
  ownership explicitly in `model()` (§6).
- **Warehouse resolution:** `ImportExportController::importProducts()` resolves
  the target warehouse via `OutletAccessService::defaultStockWarehouseFor()` and
  re-checks `canUseWarehouse()`; the request `warehouse_id` is validated with
  `BelongsToActiveBusiness('warehouses')`.

---

## 4. Vulnerable queries / lookup paths found

1. **HIGH — `CustomersImport` ownership bypass.**
   `app/Imports/CustomersImport.php` implements `WithBatchInserts`. The
   Maatwebsite `ModelManager::massFlush()` path
   (`vendor/maatwebsite/excel/src/Imports/ModelManager.php:106-138`) uses
   `$model::query()->insert(...)`, so the `creating` event and the
   `BelongsToBusiness` hook are skipped. Under tenancy every imported customer
   was created with `business_id = NULL` (unowned), breaking write isolation and
   the "no invented/unowned ownership" rule. No cross-business row could be
   selected (the file cannot name an existing customer), but the write was not
   tenant-correct.
2. **MEDIUM — Imports relied solely on model events.** Neither import class
   defended itself against a missing business context; `BelongsToBusiness`
   silently does nothing when there is no active context, so a
   directly-invoked import would create unowned rows.
3. **INFO — Global unique natural keys.** `products.barcode` and `products.sku`
   are globally unique (`database/migrations/2024_06_13_131744_create_products_table.php:20`,
   `2025_12_28_105534_add_sku_field_to_products_table.php:15`). A cross-business
   barcode/sku collision therefore cannot update another business's row (the
   scoped `updateOrCreate` finds nothing), but the subsequent insert fails on
   the unique constraint. This is intentional per the build rule to preserve
   DB constraints; see §7 and §13.

No `DB::table()` / `withoutGlobalScopes()` / `join()` export dumps were found.
`ProductsExport` and `CustomersExport` take no user-controlled filters.

---

## 5. Cross-business foreign-key protections

- `importProducts` already validated `warehouse_id` against the active business
  via `BelongsToActiveBusiness` (4A). Kept.
- The product import has **no numeric** foreign key in its format: the only
  relation is the category, expressed by **name**. `Category::firstOrCreate`
  runs through the tenant-scoped model, so an import resolves/creates a category
  owned by the active business even when another business has an identically
  named category. Tested
  (`test_import_products_resolves_category_within_active_business`).
- The customer import has no foreign keys.
- `TenantOwnership` / `BusinessContext` remain the single source of truth for
  warehouse/category ownership; no `exists:categories,id`-only validation is
  used or introduced.

---

## 6. `business_id` handling (server authoritative)

- A file column named `business_id` is **never read** by either import — both
  classes pick explicit headings only.
- **`ProductsImport`:** an explicit `$businessId` is read from
  `BusinessContext` and written into the `updateOrCreate` values. Because the
  surrounding query is business-scoped, this can only ever match/update a row
  already owned by the active business. Tested by uploading `business_id =
  Business B` and asserting the row is owned by A.
- **`CustomersImport`:** ownership is assigned explicitly into the model
  attributes so it survives the raw batch insert. A `business_id` column in the
  file is ignored.
- This preserves the chosen contract: **ignore/override client-supplied
  `business_id`; assign from context.** It never moves a record between
  businesses.

---

## 7. Update / upsert protections

- `Product::updateOrCreate(['barcode' => …])` is business-scoped, so it cannot
  select or update another business's product. Verified:
  `test_import_products_upsert_updates_only_active_business_record` (A's row is
  updated, B's row is untouched).
- A cross-business barcode cannot be hijacked:
  `test_import_products_cannot_hijack_foreign_barcode` asserts B's product is
  unchanged and no A-owned duplicate is created. The insert fails on the
  preserved global unique key.
- `CustomersImport` never updates (pure insert), so there is no customer upsert
  to hijack.

---

## 8. Storage / download findings

- Exports use `Excel::download` (streamed in-request). No `Excel::store`, no
  temporary export identifiers, and no persisted files. There is therefore no
  export-artifact authorization surface to bypass.
- `downloadTemplate` returns static headings with no tenant data.
- PDF downloads (`DocumentController`) enforce `ensureWarehouseAccess()` →
  `canUseWarehouse()` before streaming, and receive-bound `Receivable`/`Payable`
  are re-checked against their warehouse. Public invoice access is gated by the
  per-transaction `access_token`.

---

## 9. Queued operation findings

- No import/export class implements `ShouldQueue`, dispatches jobs, or uses
  `Bus`/`queue()`. Both imports and all exports run synchronously inside the
  request, where the `BusinessContext` resolved by `ResolveBusinessContext` is
  available. No post-request tenant-context problem exists.
- Per the build constraints no ad-hoc queue tenant propagation was invented.
  Future queued imports/exports (none today) would need the Jobs/Scheduler
  tenant-context work — deferred (§14).

---

## 10. Tests added

`tests/Feature/Business/ImportExportIsolationTest.php` — **17 tests / 66
assertions**. Real CSV is uploaded through the real controller entry point
(`UploadedFile::fake()->createWithContent`) and real XLSX exports are parsed
back with PHPSpreadsheet (`IOFactory`), so both reads and writes exercise the
production code path.

| # | Test | Covers |
|---|---|---|
| 1 | `test_export_products_only_contains_active_business_data` | 21.1 read isolation (products) |
| 2 | `test_export_customers_only_contains_active_business_data` | 21.1 read isolation (customers) |
| 3 | `test_transaction_export_only_contains_active_business_data` | 21.1 read isolation (transactions) |
| 4 | `test_transaction_export_rejects_foreign_warehouse_filter` | 21.2 cross-business filter |
| 5 | `test_transaction_export_accepts_own_warehouse_filter` | 21.2 own filter still works |
| 6 | `test_transaction_export_totals_exclude_other_business` | 21.3 aggregate/total isolation |
| 7 | `test_import_products_creates_record_owned_by_active_business` | 21.4 ownership on create |
| 8 | `test_import_products_rejects_foreign_warehouse` | 21.5 foreign warehouse FK |
| 9 | `test_import_products_ignores_uploaded_business_id` | 21.7 uploaded `business_id` ignored |
| 10 | `test_import_products_resolves_category_within_active_business` | 21.5 natural-key category FK |
| 11 | `test_import_products_upsert_updates_only_active_business_record` | 21.6 / 21.9 upsert isolation |
| 12 | `test_import_products_cannot_hijack_foreign_barcode` | 21.6 / 21.8 natural-key hijack |
| 13 | `test_import_customers_assigns_active_business_even_with_uploaded_business_id` | 21.4 / 21.7 mass-insert ownership regression |
| 14 | `test_import_export_routes_fail_closed_without_active_business` | 21.10 fail closed (exports + imports) |
| 15 | `test_product_import_class_fails_closed_without_context` | 21.10 class-level guard |
| 16 | `test_customer_import_class_fails_closed_without_context` | 21.10 class-level guard |
| 17 | `test_legacy_flag_off_preserves_unscoped_export_and_unowned_import` | 21.11 flag-off regression |

Adjacent existing suites re-run green:
`ProductWarehouseAssociationTest`, `OutletAccessGuardTest`, `ReportIsolationTest`,
`CrossBusinessForeignKeyTest`, `MasterDataIsolationTest`, `Tenancy/*`
(71 tests / 357 assertions).

Full suite: **547 passed (2443 assertions)**.

---

## 11. Flag-off behavior

All changes are inside `if (config('multi_business.enabled'))`. With the flag
off:

- `CustomersImport`/`ProductsImport` do not touch `business_id`, so legacy
  imports keep creating rows with the same (null) ownership as before.
- Export models have no global scope, so a legacy operator still sees every
  business's rows exactly as before 4A/4B.
- `BelongsToActiveBusiness` remains a no-op.

Regression-covered by `test_legacy_flag_off_preserves_unscoped_export_and_unowned_import`.

---

## 12. Fail-closed behavior

- With tenancy enabled the dashboard group carries `business.required`
  (`EnsureActiveBusiness`), so an authenticated request with no verified context
  is rejected with **403** before reaching the import/export controllers.
- Defensively, both import classes throw a `RuntimeException` when tenancy is
  enabled and `BusinessContext` has no active business, so no unowned row can be
  written even if the guard were bypassed. Covered by tests 14–15.
- Exports additionally fail closed at the query layer: a null context returns
  `1 = 0` for the model-scoped exports and an empty warehouse list for the
  transaction export.

---

## 13. Unresolved legacy ownership

- Legacy rows with `business_id IS NULL` (products, customers, categories) and
  null-warehouse transactions are **not** visible while tenancy is enabled. No
  ownership was invented and no backfill was run — governed by **Q7/Q9**.
- The global unique keys `products.barcode` / `products.sku` mean a business
  cannot import a key already used by another business; the insert fails rather
  than mutating the foreign row. Preserved intentionally (no DB constraint
  changes in this build). Whether these keys should become unique-per-business
  is a schema/decision item, not a 4B change.

---

## 14. Deferred work

- **Jobs / queue tenant context:** no queued imports or exports exist today; a
  queue-wide tenant-context mechanism belongs to the dedicated Jobs/Scheduler
  build. Not implemented here.
- **Per-business unique keys:** `products.barcode` / `products.sku` uniqueness is
  global; making it business-scoped is a schema decision (and was explicitly out
  of scope).
- **Webhook / integration surfaces:** not touched (no import/export webhooks).

## 15. Remaining Build 4 gaps

- REST API import/export: none exist today (`routes/api.php` has no such
  endpoints); if added they must reuse the same tenant-scoped import/export
  classes.
- Frontend import/export UI: intentionally untouched.
- Membership/role semantics (Q2/Q3/Q14) and legacy outlet/warehouse mapping
  (Q7/Q9/Q15) remain open from earlier builds.

---

### Fixed

- `CustomersImport` now assigns `business_id` from the verified server context
  so batch mass-inserts can no longer create unowned customers.
- `ProductsImport` assigns `business_id` explicitly and both imports fail closed
  when tenancy is enabled without a context.

### Verified

- Product/customer/transaction exports are business-scoped; transaction export
  warehouse filter is validated; aggregate totals exclude other businesses.
- Import upserts and category resolution are business-scoped; uploaded
  `business_id` is ignored.
- Download endpoints (Excel + PDF) enforce ownership; no stored export files or
  queued operations exist.

### Deferred

- Queue/job tenant propagation; business-scoped unique keys; future API
  import/export parity.

### Blocked by unresolved product decisions

- Q7/Q9 (legacy null-ownership rows become inaccessible under tenancy), Q15
  (legacy outlet/warehouse mapping), Q2/Q3/Q14 (membership/roles).
