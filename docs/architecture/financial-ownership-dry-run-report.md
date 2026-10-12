# Financial Ownership Dry-Run Report (Phase 2A — FIN-01)

- **Status:** Read-only dry-run implemented and validated against **controlled fixtures only**.
- **Date:** 2026-10-11
- **Command:** `php artisan business:financial-ownership-report`
- **Design:** `docs/architecture/financial-ownership-resolution-design.md`
- **Decision basis:** register §13 — FIN-01 / Q7.1 (and FIN-02/03/05).

## 1. Data-source disclosure (read this first)

> **Actual record counts are UNKNOWN.** No safe copy of the operational/production database was available in this environment. The application test database is an ephemeral in-memory SQLite database (see `phpunit.xml`) and contains **only controlled fixtures created by the test suite**. No production or staging database was queried.

- **Verified facts:** ownership graph, columns, FK behavior, classification logic (from repository + tests).
- **Fixture-based results:** classification outcomes on synthetic rows (below).
- **Unknown:** real counts of auto-resolvable / manual / ambiguous / dangling records in production.

An authorised operator must run the command against a **read replica or restored non-production copy** to obtain real counts.

## 2. How the dry-run was validated

Focused suite: `tests/Feature/Business/FinancialOwnershipDryRunTest.php` (19 tests, 56 assertions). It exercises the resolver and the command against fixtures that cover every classification and every read-only guarantee.

| # | Fixture scenario | Expected classification | Reason code |
|---|---|---|---|
| 1 | Payable → PO → warehouse with `business_id` | `AUTO_RESOLVABLE` | `auto.warehouse_business` |
| 2 | Receivable → transaction → warehouse → outlet with `business_id` | `AUTO_RESOLVABLE` | `auto.outlet_business` |
| 3 | Payable with null `purchase_order_id` | `MANUAL_ASSIGNMENT_REQUIRED` | `manual.no_parent` |
| 4 | Receivable with null `transaction_id` | `MANUAL_ASSIGNMENT_REQUIRED` | `manual.no_parent` |
| 5 | PO with null `warehouse_id` | `AMBIGUOUS_OWNERSHIP` | `ambiguous.parent_without_warehouse` |
| 6 | Warehouse with no business and no outlet (central) | `AMBIGUOUS_OWNERSHIP` | `ambiguous.unowned_warehouse` |
| 7 | Warehouse business ≠ outlet business | `AMBIGUOUS_OWNERSHIP` | `ambiguous.business_conflict` |
| 8 | Payable whose PO is missing (deferred-FK import) | `DANGLING_OR_DELETED_PARENT` | `dangling.parent_missing` |
| 9 | PO whose warehouse is missing | `DANGLING_OR_DELETED_PARENT` | `dangling.warehouse_missing` |
| 10 | Unknown record table (`invoices`) | `INVALID_OR_UNSUPPORTED_CHAIN` | `unsupported.chain` |

## 3. Classification totals

| Scope | Total | AUTO | MANUAL | AMBIGUOUS | DANGLING | UNSUPPORTED | ALREADY |
|---|---|---|---|---|---|---|---|
| payables (production) | **UNKNOWN** | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | 0 |
| receivables (production) | **UNKNOWN** | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | 0 |

`ALREADY_RESOLVED` is structurally 0 in this phase because `payables`/`receivables` have no `business_id` column.

Fixture-only example (from the automated tests, **not** production data): a set of 3 payables (1 chain-resolved, 2 manual) reports `AUTO_RESOLVABLE = 1`, `MANUAL_ASSIGNMENT_REQUIRED = 2`, `manual.no_parent = 2`.

## 4. Read-only guarantees (verified)

- The report command defines **no** `--apply` and **no** `--confirm-legacy-ownership` option (asserted).
- Running it leaves `payables`, `receivables`, `purchase_orders`, `transactions`, and `warehouses` byte-for-byte unchanged (snapshot before/after asserted).
- It adds no `business_id` column to any table.
- It performs only `SELECT` and chunked `chunkById` reads.
- `business:backfill --apply --confirm-legacy-ownership` does not mutate financial records (asserted).
- The resolver ignores `multi_business.enabled`; results are identical with the flag on or off.

## 5. Known limitations & unresolved cases

- **Production counts unknown** (no safe database). Do not treat fixture numbers as real.
- **Manual / standalone records** cannot be auto-assigned; they need FIN-02.
- **Ambiguous & dangling records** need manual reconciliation (FIN-03).
- **Quarantine** behaviour (FIN-05) is designed but not implemented; unresolved records are only classified, not hidden.
- A nullable parent is classified as `MANUAL_ASSIGNMENT_REQUIRED`, distinct from a dangling FK (`DANGLING_OR_DELETED_PARENT`).

## 6. Operator procedure for real counts

1. Point at a read replica or restored copy (never the live primary).
2. Run `php artisan business:financial-ownership-report --format=json > fin-01-report.json`.
3. Record `(timestamp, database identity, counts by classification, counts by reason)`.
4. For auto-resolvable IDs, run with `--list` (large tables may be split with `--table=payables` and `--chunk=`).
5. Treat all counts as **unverified** until reproduced by the authorised operator.
