# Financial Ownership Resolution Design (Phase 2A — FIN-01)

- **Status:** Design + read-only dry-run implemented and tested. **No migration, schema change, backfill, data mutation, feature-flag activation, or deployment was performed.**
- **Date:** 2026-10-11
- **Decision basis:** `docs/architecture/multi-business-blocker-review-and-decision-register.md` §13 — **FIN-01 / Q7.1 (hybrid ownership)**, supported by FIN-02/Q7.2 (manual assignment), FIN-03/Q7.3 (manual reconciliation), FIN-04/Q9.1 (require context for new manual records), FIN-05/Q15.1 (quarantine).
- **Implementation:** `app/Support/Tenancy/FinancialOwnershipResolver.php`, `app/Support/Tenancy/FinancialOwnershipResult.php`, `app/Console/Commands/FinancialOwnershipReportCommand.php`, `tests/Feature/Business/FinancialOwnershipDryRunTest.php`.
- **Threshold:** 100% provable ownership from persisted data. No probabilistic scoring.

## 1. Verified ownership graph

Every statement below is **FAKTA** (read from migrations/models in this repository).

### 1.1 Financial records that lack their own ownership key

| Record | Table | Parent FK | FK behavior | Own `business_id`? |
|---|---|---|---|---|
| Payable | `payables` | `purchase_order_id` → `purchase_orders.id` | nullable, `nullOnDelete` | **No** (`2026_01_02_000000`; FK added `2026_05_04_000003`) |
| Receivable | `receivables` | `transaction_id` → `transactions.id` | nullable, `nullOnDelete` | **No** (`2026_01_01_000000`) |

### 1.2 Parent chain members (warehouse-scoped)

| Table | `warehouse_id` | Own `business_id`? | Source |
|---|---|---|---|
| `purchase_orders` | nullable FK → `warehouses.id` | No | `2026_06_22_000005` |
| `transactions` | nullable FK → `warehouses.id` | No | `2026_06_22_000004` |
| `goods_receivings` | nullable FK → `warehouses.id` | No | `2026_06_22_000005` |
| `supplier_returns` | nullable FK → `warehouses.id` (also `payable_id`, `goods_receiving_id`) | No | `2026_05_05_000001` + `2026_06_22_000005` |

### 1.3 Warehouse → business

| Source | Column | Behavior |
|---|---|---|
| Direct | `warehouses.business_id` | nullable |
| Indirect | `warehouses.outlet_id` → `outlets.business_id` | both nullable |

A warehouse is **owned** only when exactly one business is proven. Central warehouses (no `business_id`, no `outlet_id`) are **not** owned and fail closed.

### 1.4 Resolved paths

```
payable#P ──purchase_order_id──▶ purchase_orders#PO ──warehouse_id──▶ warehouses#W
                                                                        ├─ business_id        (direct)
                                                                        └─ outlet_id ─▶ outlets#O.business_id

receivable#R ──transaction_id──▶ transactions#T ──warehouse_id──▶ warehouses#W ── (same as above)
```

### 1.5 Creation / mutation paths (verified)

- **Manual payable** — `PayableController::store` creates a payable with no `purchase_order_id` → `MANUAL_ASSIGNMENT_REQUIRED`.
- **Payable from receiving** — `GoodsReceivingService::createOrUpdatePayable` sets `purchase_order_id` → chain-resolvable.
- **Receivable** — created only by `CheckoutService` (pay-later) with `transaction_id`; there is no manual receivable endpoint.
- **Supplier return** — `SupplierReturnService` decrements `payable.total`; it does not create financial records.
- Payments (`payable_payments`, `receivable_payments`) inherit ownership through their payable/receivable; they are not independent backfill targets.

### 1.6 Explicitly out of scope for the blanket backfill

`business:backfill --apply` maps only `products, categories, customers, suppliers, units, price_lists, outlets, warehouses`. It does **not** touch `payables` or `receivables`. This is verified by test `test_blanket_business_backfill_does_not_touch_financial_records`.

## 2. Classification rules

`FinancialOwnershipResolver::resolve($table, $id)` returns one classification and one machine-readable reason code. It never writes and never consults the active business context, the acting user, timestamps, a single-outlet count, a default business, or global settings.

| Classification | Reason code | Condition |
|---|---|---|
| `AUTO_RESOLVABLE` | `auto.warehouse_business` | Chain complete; `warehouse.business_id` non-null, no conflicting outlet. |
| `AUTO_RESOLVABLE` | `auto.outlet_business` | `warehouse.business_id` null; `warehouse.outlet_id` → outlet with non-null `business_id`. |
| `MANUAL_ASSIGNMENT_REQUIRED` | `manual.no_parent` | Parent FK is null (manual/standalone record). |
| `AMBIGUOUS_OWNERSHIP` | `ambiguous.parent_without_warehouse` | Parent exists but `warehouse_id` is null. |
| `AMBIGUOUS_OWNERSHIP` | `ambiguous.unowned_warehouse` | Warehouse has no `business_id` and no owned outlet. |
| `AMBIGUOUS_OWNERSHIP` | `ambiguous.business_conflict` | `warehouse.business_id` ≠ `outlet.business_id`. |
| `DANGLING_OR_DELETED_PARENT` | `dangling.parent_missing` | Parent FK non-null but parent row missing. |
| `DANGLING_OR_DELETED_PARENT` | `dangling.warehouse_missing` | `warehouse_id` non-null but warehouse row missing. |
| `DANGLING_OR_DELETED_PARENT` | `dangling.outlet_missing` | `outlet_id` non-null but outlet row missing. |
| `ALREADY_RESOLVED` | `resolved.already` / `resolved.conflict` | Record already has a persisted, valid `business_id` (defensive; currently unreachable). |
| `INVALID_OR_UNSUPPORTED_CHAIN` | `unsupported.chain` | Unknown table/parent type, or record missing at classification time. |

Anything that is not exactly one proven business **fails closed** and is never proposed for automatic assignment.

## 3. Confidence & evidence

For each `AUTO_RESOLVABLE` record, `FinancialOwnershipResult::toArray()` exposes:

- `table`, `id`
- `existing_business_id` (null in this phase)
- `parent_table`, `foreign_key`, `parent_id`
- `warehouse_id`, `warehouse_business_id`, `outlet_id`, `outlet_business_id`
- `parent_business_id` (the proven business)
- `classification`, `reason_code`, human-readable `explanation`
- `proposed_business_id` (only when conclusively proven)

No financial amounts or PII are included in evidence or reports.

## 4. Failure modes & safeguards

1. **No probabilistic assignment** — there is no score/percentage path. 90/95/99% are rejected by construction.
2. **Read-only by construction** — the report command has no `--apply`, no `--confirm-legacy-ownership`, and no mutating code path (asserted by test).
3. **Fail closed** — unknown table, missing parent, missing intermediate, unowned warehouse, and conflict all return a non-auto classification.
4. **Active context is ignored** — the resolver uses raw table reads, so tenancy global scopes cannot silently narrow or widen the result.
5. **Dual confirmation preserved** — applying ownership stays with `business:backfill`, which requires both `--apply` and `--confirm-legacy-ownership`; financial records are not part of its blanket map.
6. **Dangling references** — under enforced foreign keys a dangling reference cannot normally exist; `nullOnDelete` turns a deleted parent into a null parent (`MANUAL_ASSIGNMENT_REQUIRED`). The resolver still detects the dangling case defensively for imported/legacy data (covered by tests using deferred FK enforcement).

## 5. Manual reconciliation requirements (FIN-02 / FIN-03 / FIN-05)

- `MANUAL_ASSIGNMENT_REQUIRED` → administrator assigns the business (FIN-02). Not in this phase.
- `DANGLING_OR_DELETED_PARENT` → manual reconciliation (FIN-03).
- `AMBIGUOUS_OWNERSHIP` / `INVALID_OR_UNSUPPORTED_CHAIN` → manual review; must not be auto-assigned.
- Under FIN-05, unresolved records must be quarantined and excluded from normal tenant operations once tenancy is enabled. Quarantine columns are **not** introduced in this phase.

## 6. How to run the dry-run (read-only)

```bash
# Human summary (no writes)
php artisan business:financial-ownership-report

# Machine-readable JSON (no writes)
php artisan business:financial-ownership-report --format=json

# One type, include per-record identifiers/reason codes, smaller chunks
php artisan business:financial-ownership-report --table=payables --list --chunk=250
```

The command runs regardless of `multi_business.enabled` because it only reads. It never writes and adds no columns.

## 7. Known limitations

- `ALREADY_RESOLVED` is unreachable in this phase: `payables`/`receivables` have no `business_id` column. The overlay is retained for the future schema phase.
- A nullable parent is **not** a dangling reference; the two are classified separately (FIN-01 requires this distinction).
- Reports reflect only the database they run against. Fixture-based results do **not** represent production data (see `financial-ownership-dry-run-report.md`).
- Chunked reads (`chunkById`) keep memory bounded; parents are fetched per record, so parent relationships are resolved correctly across chunk boundaries.
- **Defects confirmed by inspection (2026-10-11, command unchanged):** `--format=json` combined with `--list` produces invalid JSON (list lines are emitted before the JSON payload); the JSON `limitations` text is static and will wrongly claim `ALREADY_RESOLVED` is unreachable once the column exists; the JSON lacks DB/server provenance fields. See `financial-ownership-audit-runbook.md` §A.2 and the design §10 defect register (D7–D9); fixes are separate code changes pending approval.
- **Not a consistent snapshot:** the report does not wrap its scan in a transaction, so on a live replica rows may change mid-scan; use a static restored copy for authoritative counts (runbook §B.0/B.5).

## 8. Next required approvals (not part of Phase 2A)

1. Schema approval to add `payables.business_id` / `receivables.business_id` (nullable) and the quarantine field for FIN-05.
2. An apply-mode design (separate task) that writes only `AUTO_RESOLVABLE` records, never `MANUAL`/`AMBIGUOUS`/`DANGLING`, with dual confirmation.
3. Manual assignment and quarantine workflows (FIN-02 / FIN-05).
