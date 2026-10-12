# Multi-Business Phase 4F — Financial Ownership FIN-01/02/03/05 (Implementation Notes)

Status: **Schema + apply-mode + quarantine + FIN-04 implemented, flag OFF.** No production access/migration, no apply-mode mutation of any real DB, no deployment. `multi_business.enabled` remains `false`. Code is ready for owner review; run `business:financial-ownership-backfill` (evaluate → apply) and `business:financial-ownership-assign` on an authorised replica **before** ever enabling the flag.

## 1. Scope of this build

Implements the FIN-01/02/03/05 recommendations recorded in §13.13 of the decision register (and the FIN-01 schema/operations design) for the financial ledger:

- **FIN-01** — persisted six-state ownership lifecycle (`business_id` + `ownership_status`) with a validated apply-mode backfill.
- **FIN-02** — explicit manual ownership assignment (quarantine exit) with fail-closed rules.
- **FIN-03** — reconciliation via a read-only report command (defects D7/D8/D9 fixed).
- **FIN-05** — quarantine: any evaluated-but-not-resolved row is excluded from model reads and blocked from mutations/payments.
- **FIN-04** — new-record creation paths stamp ownership when provable (checkout receivables, manual payables).
- **Defect fixes D3/D6** (persisted-vs-chain conflict → `ambiguous`; per-row audit with `run_id`).
- **D4** — FK choice implemented as **`restrictOnDelete`** (businesses referenced by financial rows archive, never hard-delete). Recorded for owner veto.

Explicitly **not** in this slice (deferred at the time): UNIQUE tenant-scoped backfill, SETTINGS business_id/precedence, SCHED/job tenancy (F12), full AUTH enforcement (residual `teams=false`), Phase 8 UI, and any run against production/replica. *(SETTINGS/UNIQUE/SCHED/AUTH + Phase-8-lite UI were implemented later — see the decision register §13.14.)*

## 2. Schema (3 additive migrations)

| Migration | Change |
|---|---|
| `2026_10_11_100001_add_business_id_to_payables_table` | `payables.business_id` nullable FK `businesses` **RESTRICT**; index `[business_id,status]`, `[business_id,due_date]`, `[business_id,supplier_id]`; guards `hasColumn` |
| `2026_10_11_100002_add_business_id_to_receivables_table` | `receivables.business_id` nullable FK `businesses` **RESTRICT**; index `[business_id,status]`, `[business_id,due_date]`, `[business_id,customer_id]`; guards `hasColumn` |
| `2026_10_11_100003_add_ownership_status_to_financial_tables` | `ownership_status` string(20) default `'unassigned'` + index; `ownership_evaluated_at` timestamp nullable; `ownership_note` text nullable; index `[business_id,ownership_status]` on both tables |

Write-invariant: **evaluation writes status only**; `business_id` is written exclusively by the validated apply backfill or manual assignment. The invariant is enforced by routing every write through `FinancialOwnershipService`.

## 3. Ownership lifecycle (six states + derived quarantine)

Row state machine (default `unassigned`):

```
unassigned ──evaluate──> manual_required / ambiguous / dangling / unsupported   (status only)
unassigned ──apply──>    resolved          (auto, chain-proven, business_id written)
resolved ──re-evaluate──> resolved          (idempotent) | ambiguous (persisted-vs-chain conflict, D3/D5)
manual_required/ambiguous/dangling/unsupported ──assign (FIN-02)──> resolved
```

- **`resolved`** — ownership proven by validated backfill or explicit manual assignment; `business_id` authoritative.
- **Quarantine** is a derived predicate: `ownership_status != 'resolved'` and is **never terminal** — FIN-05 keeps reconciliation open.
- Evaluation (`evaluateTable`) never guesses a business: `AUTO_RESOLVABLE` rows stay `unassigned` until apply.

## 4. Read barrier & quarantine (models + TenantOwnership)

- `app/Support/Tenancy/FinancialOwnershipScope.php` — global scope on `Payable`/`Receivable`. Active only when `multi_business.enabled = true`. Then a model query sees:
  1. `ownership_status = 'resolved'` AND `business_id = active business`, OR
  2. never-evaluated rows (`unassigned`/NULL) — the FIN-01 transition window, where the caller's chain filtering (service scopes + TenantOwnership) remains the boundary **exactly as in phase 4E**.
  - Every evaluated-but-not-resolved row is excluded **even when the parent chain proves a business or `business_id` matches** (design §2.2/§2.3 — quarantine blocks reads, mutations and payments, D5).
  - Without an active context the scope never leaks resolved records of other businesses; the unassigned transition behaviour mirrors phase 4E. Reconcile/admin surfaces use `withoutGlobalScope(...)` or `DB::table`.
  - Flag off ⇒ strict no-op, byte-for-byte legacy behaviour.
- `app/Support/Tenancy/TenantOwnership.php` — `VIA_PARENT` branch now prefers persisted `business_id` when `ownership_status = 'resolved'`, fails closed on quarantined rows, and keeps the chain fallback for `unassigned` rows (the 26-test `AuthorizationBoundaryIsolationTest` stays green unchanged).

## 5. Services & commands

### `app/Services/FinancialOwnershipService.php`
- `statusFor(Result)` — classification→status map; `resolved.conflict` ⇒ `ambiguous`.
- `businessIdForWarehouse(int)` / `stampFromWarehouse(int)` — FIN-04 creation stamp; conflict (warehouse says A, outlet says B) fails closed with null.
- `evaluateTable()` — status-only; `AUTO_RESOLVABLE` stays `unassigned`.
- `applyBackfill()` — per-row `DB::transaction` + `lockForUpdate`; writes `business_id` only for chain-proven rows; already-owned rows are skipped (idempotent, D6); persisted-vs-chain conflict flips to `ambiguous`; per-row `AuditLogService::log` with `run_id` + resolver evidence.
- `assign()` (FIN-02) — rejects inactive business, missing record, unreasoned reassignment, and unacknowledged chain conflicts; writes `business_id` + `resolved` + audit.

### Commands
- `business:financial-ownership-backfill` — default **dry-run read-only**; `--apply` requires `--confirm-financial-ownership` (dual confirmation); `--table=all|payables|receivables`, `--chunk`, `--limit` (staged rollout), `--report=<json>` (run report with provenance); exits non-zero when records require manual review.
- `business:financial-ownership-assign` — `{table} {id} {business}` + `--note/--reason/--override-conflict/--actor`.
- `business:financial-ownership-report` (read-only) — D7 provenance (`driver/database/app_env/app_version/arguments`), D8 `--format=json --list` emits a `records` array, D9 the "ALREADY_RESOLVED unreachable" limitation is now conditional on schema presence.

## 6. FIN-04 creation paths

- `CheckoutService` (pay-later) — `Receivable` stamped via `stampFromWarehouse(activeShift.warehouse_id)`: `business_id` + `resolved` when the warehouse proves a business, else `unassigned` (never a guess).
- `PayableController::store` — when the flag is on, an active business context is required; web requests are already rejected (403) by `EnsureActiveBusiness`; the controller aborts 422 as defense-in-depth and stamps `business_id` + `resolved`.

## 7. Permissions

`PermissionSeeder`: `financial-ownership-reconcile`, `financial-ownership-assign`, `financial-ownership-quarantine`. `RoleSeeder` excludes `financial-ownership-%` from manager (super-admin only — platform-level authority). Existing permission cache: logout + login on affected environments.

## 8. Tests

- New: `FinancialOwnershipSchemaTest` (4) — column presence/defaults, RESTRICT FK (business referenced by payable/receivable cannot be deleted), fillable stamping.
- New: `FinancialOwnershipBackfillTest` (16) — dry-run writes nothing; `--apply` demands dual confirmation; only chain-proven rows written; idempotency; staged `--limit`; per-row audit with `run_id`; conflict ⇒ non-zero exit + `ambiguous`; JSON run report provenance; report D7/D8/D9; FIN-02 workflow (assign, reassign reason, chain-conflict override, inactive business, command failures).
- New: `FinancialOwnershipQuarantineTest` (10) — resolved/foreign/transition visibility; evaluated-non-resolved quarantine (incl. chain-proven and matching-business rows); no-context behaviour; flag-off no-op; TenantOwnership precedence/quarantine/chain fallback; FIN-04 controller 403-without-context + context stamping; `stampFromWarehouse` positive/null.
- Updated: `FinancialOwnershipDryRunTest` — two assertions now expect the ownership schema (columns present; ALREADY_RESOLVED still unreachable for fresh unowned rows).
- Phase-4E contract (FallbackTest, AuthorizationBoundaryIsolationTest) unchanged and green.
- Full suite: **667 passed (2791 assertions)** (baseline 637). `vendor/bin/pint` clean on all touched PHP.

## 9. Flag & rollout notes (for the operator)

- `multi_business.enabled` remains `false`; config/compat untouched; DB mutations in tests only (SQLite in-memory).
- Order of operations before enabling the flag on an authorised environment:
  1. `php artisan migrate`
  2. `business:financial-ownership-report --format=json --list` (read-only baseline)
  3. `business:financial-ownership-backfill` (dry-run), then `--apply --confirm-financial-ownership` (idempotent; can be `--limit`-staged)
  4. `business:financial-ownership-assign` for `manual_required`/`ambiguous`/`dangling`
  5. Only then evaluate `MULTI_BUSINESS_ENABLED=true`.

## 10. Deferred (unchanged blockers, no silent re-resolution)

UNIQUE tenant-scoped (needs non-null backfill on production data), SETTINGS business_id/precedence, SCHED/job tenancy (F12), full AUTH enforcement (residual `teams=false`), Phase 8 UI. Do not enable the flag or run apply-mode against production before owner approval (§13.13 / §13.12(b)).

> **Update 2026-10-12:** SETTINGS, tenant-scoped UNIQUE (UNIQUE-01/02/03/05/06/07), SCHED per-business iteration, AUTH enforcement, and the Phase-8-lite UI permissions plumbing are now implemented (decision register §13.14). UNIQUE-08/09/10/11, otentikasi schema `business_id` untuk `customer_campaigns`/`customer_segments`, the production runbook/backfill, full Fase 8 (onboarding/rollout, Q6), flag activation, and deployment remain deferred and require separate owner approval. The flag stays `false`.