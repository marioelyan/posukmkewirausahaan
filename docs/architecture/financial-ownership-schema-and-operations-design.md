# FIN-01/02/03/05 — Financial Ownership: Schema & Operational Design Proposal

- **Status:** **DESIGN PROPOSAL ONLY.** No schema change, migration, data change, apply mode, deployment, or feature-flag activation has been performed or is authorized by this document.
- **Date:** 2026-10-11
- **Approved baseline:** Phase 2A read-only resolver + dry-run report (`financial-ownership-resolution-design.md`, `financial-ownership-dry-run-report.md`), accepted as the ownership-resolution baseline.
- **Decision basis:** register §13 — **FIN-01/Q7.1** (hybrid ownership), **FIN-02/Q7.2** (manual assignment), **FIN-03/Q7.3** (manual reconciliation), **FIN-04/Q9.1** (active context for new manual records), **FIN-05/Q15.1** (quarantine). Auth context: **AUTH-01/02/03** (membership-first hybrid; Spatie `teams=false`).
- **Related:** `adr/ADR-02-payables-receivables-legacy-ownership.md`, `multi-business-readonly-audit-queries.md`, `financial-ownership-audit-runbook.md`, `app/Support/Tenancy/FinancialOwnershipResolver.php`.

> **How to read this document.** Items marked **[REC]** are technical recommendations from the implementation team. Items marked **[APPROVAL]** require explicit owner sign-off before any implementation. Nothing here is an action taken.

---

## 0. Scope, non-goals, and separation of authority

**In scope (design only):** nullable `business_id` fields; FK/index strategy; quarantine representation and enforcement; manual assignment (FIN-02); reconciliation (FIN-03); production audit plan; apply-mode contract; migration/backfill/validation/rollback plans; required tests and acceptance criteria.

**Non-goals / explicitly NOT approved by this document:**
- Creating or executing migrations; adding `business_id` or quarantine fields.
- Running apply mode; mutating any record.
- Implementing manual-assignment or quarantine workflows.
- Enabling `multi_business.enabled`; deploying.
- AUTH/SETTINGS/UNIQUE/scheduler implementation.

**Enforcement note:** the fields below are **inert while `multi_business.enabled=false`**. All enforcement described in §2 is only active when the flag is on, which is a **separate [APPROVAL]**.

---

## 1. Proposed nullable `business_id` fields and FK/index strategy

### 1.1 Scope of new columns

Only the two financial ledger tables that lack their own ownership key:

| Table | New column | Nullable | Default |
|---|---|---|---|
| `payables` | `business_id` | yes | `NULL` |
| `receivables` | `business_id` | yes | `NULL` |

**[REC] Do not add `business_id` to** `purchase_orders`, `transactions`, `goods_receivings`, `supplier_returns` (they remain warehouse-scoped), nor to `payable_payments`/`receivable_payments` (they inherit through their parent). Duplicating ownership on children adds drift risk with no query benefit.

**Cross-business dependency [APPROVAL]:** because per-business `business_id` for other tables is governed by UNIQUE/Q4 and SETTINGS/Q5 decisions, the FK target must remain a single canonical `businesses.id`. No tenant-local business keys.

### 1.2 Foreign-key strategy

**[REC]** `->nullable()->constrained('businesses')->restrictOnDelete()`.

- **Why `restrictOnDelete` (not `nullOnDelete`):** deleting a business must not silently orphan financial records into an unowned state. Businesses are expected to be **archived** (`businesses.status = archived`) rather than hard-deleted. `restrict` makes accidental hard-deletion fail loudly.
- **If hard-deletion is ever required**, the fallback is `nullOnDelete` + automatic quarantine of the affected rows. This must be an explicit owner decision, not a default.
- FK name follows Laravel convention: `payables_business_id_foreign`, `receivables_business_id_foreign`.

### 1.3 Index strategy

Existing indexes already include `payables.document_number`, `receivables.invoice`, and the parent FK indexes. **[REC] add:**

| Table | Index | Purpose |
|---|---|---|
| `payables` | `(business_id, status)` | tenant list/aging by status |
| `payables` | `(business_id, due_date)` | aging / due-soon |
| `payables` | `(business_id, supplier_id)` | supplier statement |
| `receivables` | `(business_id, status)` | tenant list/aging |
| `receivables` | `(business_id, due_date)` | aging |
| `receivables` | `(business_id, customer_id)` | customer statement |
| both | `(ownership_status)` | reconciliation/quarantine scans |
| both | `(business_id, ownership_status)` | tenant reads excluding quarantine |

**MySQL NULL caveat [REC]:** composite indexes with `business_id IS NULL` still index fine for reads, but any future **unique** constraint involving `business_id` treats NULLs as distinct. This matters for UNIQUE/Q4, not for these non-unique indexes. Backfill should precede any future uniqueness work.

### 1.4 Migration shape (proposed names, not created)

```
database/migrations/2026_10_XX_000001_add_business_id_to_payables_table.php
database/migrations/2026_10_XX_000002_add_business_id_to_receivables_table.php
database/migrations/2026_10_XX_000003_add_ownership_status_to_financial_tables.php
```

**[REC]** Split into small additive migrations; each `down()` drops only what it added; no data backfill inside schema migrations (backfill is a separate command, §5/§6).

### 1.5 Resolver interaction

`FinancialOwnershipResolver` already detects a persisted `business_id` and returns `ALREADY_RESOLVED` (`resolved.already` / `resolved.conflict`). **[REC]** no resolver change is needed to support the new column; it will simply become reachable after the backfill, and its conflict reason becomes meaningful during reconciliation.

### 1.6 Engine and schema compatibility (verified this revision)

Read from the repository only; no database was contacted.

| Fact | Evidence |
|---|---|
| `payables` / `receivables` have **no** `business_id` (nor `warehouse_id`) | `2026_01_02_000000_create_suppliers_and_payables_tables.php`, `2026_01_01_000000_create_receivables_tables.php` |
| `payables.purchase_order_id` → `purchase_orders` and `receivables.transaction_id` → `transactions` are nullable FKs with `nullOnDelete` | `2026_05_04_000003`, `2026_01_01_000000` |
| `warehouses.business_id` **and** `warehouses.outlet_id` exist (both nullable, `nullOnDelete`) | `2026_10_10_160000_add_business_id_to_master_data_tables.php`, `2026_09_14_000002` |
| `outlets.business_id` exists (nullable, `nullOnDelete`) | `2026_10_10_150300_add_business_id_to_outlets_table.php` |
| FK target `businesses` exists with `status ∈ {active, suspended, archived}` and `scopeActive` | `2026_10_10_150216_create_businesses_table.php`, `Business` model |
| Production engine is **MySQL** (`DB_CONNECTION=mysql`); tests use in-memory **SQLite** | `.env.example`, `phpunit.xml` |
| The report command + resolver use only portable Query Builder / `Schema` APIs and are engine-agnostic | `FinancialOwnershipReportCommand`, `FinancialOwnershipResolver` |

Detailed compatibility checks, the corrected audit SQL, and the defect register
are in `financial-ownership-audit-runbook.md`.

---

## 2. Quarantine representation and exclusion

### 2.1 Representation options

| Option | Description | Pros | Cons |
|---|---|---|---|
| A. Boolean `is_quarantined` | Single flag | Simple | Loses reason/history; can't distinguish manual vs ambiguous vs dangling |
| B. Enum `ownership_status` | `unassigned / resolved / manual_required / ambiguous / dangling / unsupported` | Mirrors resolver output; deterministic filtering; auditable | Slightly wider |
| C. Timestamp+reason | `quarantined_at`, `quarantine_reason` | Minimal | No state for "unassigned vs resolved" |
| D. Separate table | `financial_ownership_quarantine` | Keeps ledger lean | Extra join; can drift; more moving parts |

**[REC] Option B, with history in `audit_logs`:**

`ownership_status` is the record's **resolution state**. There are exactly **six**
values; "quarantine" is a **derived predicate**, not a stored seventh value.

| Column | Type | Default | Notes |
|---|---|---|---|
| `ownership_status` | string(20), indexed | `unassigned` | one of the six resolution states below |
| `ownership_evaluated_at` | timestamp nullable | `NULL` | when the resolver last classified the row |
| `ownership_note` | text nullable | `NULL` | operator note (no PII) |

Resolution states (exactly six) and the only legal transitions:

```
unassigned ──backfill(AUTO)──────────────▶ resolved
     │
     ├─evaluate─▶ manual_required ──assign(actor, FIN-02)──▶ resolved
     ├─evaluate─▶ ambiguous       ──reconcile(FIN-03)─────▶ resolved
     ├─evaluate─▶ dangling        ──reconcile(FIN-03)─────▶ resolved
     └─evaluate─▶ unsupported     ──reconcile(FIN-03)─────▶ resolved
```

**Corrected quarantine model** (this resolves the earlier six-vs-seven-state
ambiguity, where the diagram introduced a seventh `quarantined` value not in the
enumerated set):

- **Quarantine is derived, not stored:** a record is *quarantined for tenant
  purposes* **iff `ownership_status != 'resolved'`** (FIN-05). There is **no**
  `quarantined` value and no separate boolean.
- **Quarantine is not terminal:** FIN-05 requires quarantined records to remain
  reachable "only via reconciliation/admin workflow"; the only terminal state in
  the six-state set is `resolved`. An unresolved record stays quarantined until it
  becomes `resolved` through FIN-02/FIN-03.
- `resolved` ⟺ `business_id IS NOT NULL` (invariant to be validated).
- Full history (who changed what, when, why) lives in the existing `audit_logs`
  (no new audit table).

**Alternative for owner consideration [APPROVAL].** If the owner wants a durable
"reviewed, will not resolve" marker distinct from "pending", add a seventh
explicit value `quarantined` plus a rule that only an administrator may set it.
This is **not** recommended by default: it duplicates the derived predicate and
adds a state FIN-05 already covers. Adopt only on explicit approval.

**Resolver classification → resolution state (explicit mapping).** The resolver's
`classification` and `reason_code` are **evidence**, not the stored state. Store
`ownership_status` from this mapping; store the classification/reason code in the
backfill/reconciliation **audit record** (`audit_logs`), never in
`ownership_status`.

| Resolver classification | Resolver reason code(s) | `ownership_status` |
|---|---|---|
| `AUTO_RESOLVABLE` | `auto.warehouse_business`, `auto.outlet_business` | `resolved` **after** backfill apply; `unassigned` before |
| `MANUAL_ASSIGNMENT_REQUIRED` | `manual.no_parent` | `manual_required` |
| `AMBIGUOUS_OWNERSHIP` | `ambiguous.parent_without_warehouse`, `ambiguous.unowned_warehouse`, `ambiguous.business_conflict` | `ambiguous` |
| `DANGLING_OR_DELETED_PARENT` | `dangling.parent_missing`, `dangling.warehouse_missing`, `dangling.outlet_missing` | `dangling` |
| `ALREADY_RESOLVED` | `resolved.already` (consistent) / `resolved.conflict` (needs review) | `resolved` / `ambiguous` on conflict |
| `INVALID_OR_UNSUPPORTED_CHAIN` | `unsupported.chain` | `unsupported` |

### 2.2 Exclusion from tenant reads

**[REC]** introduce a shared scope used by the financial read paths (controllers + `PayableAgingService` + `ReceivableService`), active only when `multi_business.enabled=true`:

```text
WHERE business_id = :activeBusinessId
  AND ownership_status = 'resolved'          -- quarantine exclusion
```

- This replaces the Phase-1 parent-chain/warehouse join as the **authoritative** tenant filter once `business_id` is populated. During the transition window (column added, backfill not yet complete), reads may keep the parent join for `business_id IS NULL` rows but **must still exclude non-`resolved` rows**.
- The legacy ≤1-outlet fallback stays disabled under tenancy (already true from Phase 1).
- System/background and reconciliation paths opt out explicitly with `withoutGlobalScope` + a dedicated permission, never implicitly.

### 2.3 Exclusion from mutations

**[REC]** centralize a guard (policy or `ensureAccess` extension) that, when tenancy is on, denies `show`/`pay`/`update`/`export`/`share` for any record whose `ownership_status != 'resolved'` (or whose `business_id` ≠ active business). This extends the existing Phase-1 fail-closed behavior to the new column and makes it deterministic (no reliance on outlet counts).

- `payable_payments`/`receivable_payments` inherit: a quarantined parent rejects payment.
- Public portal (`portal.receivable.pay`) must apply the same guard.
- Denial is `404` for tenant users (no existence leak) and `403` only for reconciliation/admin actors on explicitly allowed routes.

### 2.4 Visibility for reconciliation/admin

- Reconciliation surfaces use `withoutGlobalScope` + `permission:financial-ownership-reconcile` **[APPROVAL]** (new permission; not created now).
- Reports (§4) run read-only across all statuses.
- Quarantined rows remain stored for audit; nothing is deleted by quarantine.

---

## 3. Manual assignment (FIN-02) and reconciliation (FIN-03) workflow

### 3.1 Authorization

| Action | Requirement **[REC]** |
|---|---|
| View unresolved/quarantined | `payables-access`/`receivables-access` **plus** `financial-ownership-reconcile` |
| Assign `business_id` to a `manual_required` record | `financial-ownership-assign` **plus** active membership with role `owner`/`manager` for the target business (AUTH-01/02/03) |
| Reassign a `resolved` record | requires reason + step-up password confirmation (`step_up` middleware) |
| Platform super-admin | separate, explicit platform path (AUTH-01); never implicit |

**[APPROVAL]** All three new permissions (`financial-ownership-reconcile`, `financial-ownership-assign`, `financial-ownership-quarantine`) require owner approval and seeder changes. **No permission is created by this proposal.**

### 3.2 Validation rules **[REC]**

1. Target business must exist and be `active`.
2. Actor must have an active `business_user` membership for the target business (super-admin uses the explicit platform path).
3. Record must currently be non-`resolved`. A second assignment to an already-`resolved` record is rejected unless the actor explicitly requests reassignment with a reason.
4. Assignment sets `business_id`, `ownership_status='resolved'`, `ownership_evaluated_at=now()`, and records `ownership_note`.
5. Server-side only: the business id is taken from the verified context/selected business, never from a request body value that could move ownership.
6. Assignment is rejected for records whose chain proves a **different** business (`resolved.conflict`) unless the actor acknowledges the conflict explicitly (then the audit marks it as an override).

### 3.3 Audit trail **[REC]**

Use the existing `AuditLogService` (no new table):

- `event: financial.ownership_assigned` — `before{business_id,ownership_status}`, `after{...}`, `meta{reason, source: dry-run|manual, resolver_reason_code}`, actor, IP/UA.
- `event: financial.ownership_quarantined` / `financial.ownership_unquarantined`.
- `event: financial.ownership_backfill_applied` — per row (or per batch with a run id; see §5).

### 3.4 Reconciliation workflow **[REC]**

1. Operator runs the read-only report (§4) against a replica/copy.
2. For `manual_required`: assign business via §3.2.
3. For `ambiguous`/`dangling`/`unsupported` (FIN-03): investigate the chain (parent restored? outlet/warehouse corrected?), then assign or quarantine.
4. Re-run the report; unresolved totals must decrease monotonically until signed off.
5. Quarantine (FIN-05) applies to **every non-`resolved` record** (derived predicate) and is **not terminal**: unresolved records stay retained, hidden from tenants, and immutable except via reconciliation, and leave quarantine only by becoming `resolved` (FIN-02/FIN-03).

---

## 4. Production read-only audit plan

Goal: obtain **actual** classification counts, conflicts, and unresolved totals without writing anything.

**Preconditions:** read replica or restored copy; `multi_business.enabled` irrelevant (report is flag-independent and read-only).

**Steps:**

1. **Integrity & hierarchy pre-checks (read-only):**
   - `php artisan outlet:audit --strict` (outlet/warehouse ownership completeness).
   - `php artisan outlet:legacy-audit` (classify warehouse-less/outlet-less records).
2. **Financial classification:**
   - `php artisan business:financial-ownership-report --format=json > fin-01-replica.json`
   - Optionally `--table=payables` / `--table=receivables`, and `--list --max-ids=` for identifiers.
3. **Conflict inventory:** run the conflict queries in `multi-business-readonly-audit-queries.md` §2.5–2.6 (warehouse vs outlet mismatch, null intermediates).
4. **Cross-check totals:** per-classification counts must equal the sums of per-reason counts; `AUTO + MANUAL + AMBIGUOUS + DANGLING + UNSUPPORTED + ALREADY = total`.
5. **Capture:** store `(timestamp, db identity, counts by classification, counts by reason, conflict totals, unresolved totals, auto-resolvable business distribution)`.
6. **Sign-off:** operator confirms the numbers; only then is backfill (§5) considered.

**Measurable outputs:** a JSON artifact plus a one-page summary. **No production query may be run outside a replica/copy without separate written authorization.** The step-by-step operator procedure (connection verification, read-only safeguards, capture, count reconciliation, evidence retention) is in `financial-ownership-audit-runbook.md`.

---

## 5. Backfill apply-mode contract

**Core rule [REC]:** apply mode may write **only** records the resolver classifies `AUTO_RESOLVABLE` **at apply time**. Everything else is skipped and reported.

### 5.1 Command interface (proposed)

```
business:financial-ownership-backfill
  {--apply}                              # default is dry-run
  {--confirm-financial-ownership}        # REQUIRED with --apply; separate from the master-data backfill
  {--table=all}                          # payables | receivables | all
  {--chunk=500}
  {--limit=}                             # optional cap, for staged rollout
  {--report=}                            # optional path to write a JSON run artifact
```

**[REC]** use a **dedicated** command and a **distinct** confirmation flag (`--confirm-financial-ownership`). Do **not** extend the master-data `business:backfill` blanket map, which must continue to leave financial records untouched.

### 5.2 Algorithm

1. **Dry-run (default):** classify every row; emit counts + proposed assignments; write nothing.
2. **Apply requires both** `--apply` **and** `--confirm-financial-ownership`; missing either → exit failure, no writes.
3. **Re-verify per row inside the transaction** (do not trust a prior report): re-run `FinancialOwnershipResolver::resolve()`; only proceed if still `AUTO_RESOLVABLE` and the record's `business_id IS NULL`.
4. **Chunked batching:** `chunkById(chunk)`; each batch in its own `DB::transaction`; `lockForUpdate()` on the selected rows to prevent concurrent mutation.
5. **Skip & report** (never write) if at apply time: `business_id` already set (idempotency), classification changed to non-auto, or a conflict is detected.
6. **Per-row audit** (`financial.ownership_backfill_applied`) with a shared `run_id`.
7. **Exit codes:** `0` success; non-zero if any conflict requires manual review.
8. **Idempotent:** re-running writes 0 rows (guarded by `business_id IS NULL`).

### 5.3 Failure recovery

- A batch failure rolls back **only that batch**; already-committed batches remain (their rows are audited with the run id).
- Re-running resumes naturally (idempotent).
- **Never** set ownership from a stale report file; always re-resolve live.
- A `--limit` allows staged rollout with review between stages.

### 5.4 Dry-run comparison

- Before apply, capture the dry-run classification snapshot.
- After apply, re-run the report; assert: every row that was `AUTO_RESOLVABLE` and written is now `ALREADY_RESOLVED` with the same business; **no other row changed**.
- Any mismatch aborts review (no further writes) pending investigation.

### 5.5 Explicit dual confirmation

- Layer 1: `--apply` (opt into writing).
- Layer 2: `--confirm-financial-ownership` (acknowledge that a *verified financial ownership* write is happening).
- **[REC]** optionally require the operator to also pass the target environment in production (`--env-check`), mirroring existing operational safeguards. **[APPROVAL]** for any additional production interlock.

---

## 6. Migration, backfill, validation, and rollback plans

### 6.1 Migration plan (additive, reversible)

| Step | Action | Reversible? |
|---|---|---|
| M1 | Add `payables.business_id` (nullable, FK `restrict`, indexes) | Yes (drop column/index) |
| M2 | Add `receivables.business_id` (nullable, FK `restrict`, indexes) | Yes |
| M3 | Add `ownership_status` / `ownership_evaluated_at` / `ownership_note` (indexed) | Yes |
| M4 | (No data moved in migrations) | n/a |

**Not in migrations:** backfill (separate command), quarantine decisions, permission seeding (**[APPROVAL]**).

### 6.2 Backfill plan

1. Read-only audit (§4) → real counts.
2. Dry-run backfill → proposed assignments.
3. Apply with dual confirmation (staged via `--limit` if needed).
4. Mark evaluated-but-not-auto rows as `manual_required`/`ambiguous`/`dangling`/`unsupported` (status only; no ownership guess).
5. Reconcile (§3.4); quarantine terminal cases (FIN-05).

### 6.3 Validation plan

- **Invariant:** `ownership_status='resolved'` ⟺ `business_id IS NOT NULL`.
- **Completeness:** `resolved + quarantined-non-resolved = total`.
- **No cross-business drift:** for every written row, the parent chain still resolves to the written business.
- **Financial reconciliation:** aging/statement totals per business before vs after must be explainable solely by the resolved/quarantine split (no amounts moved between businesses).
- **Regression:** full test suite green; legacy behavior unchanged while flag off.

### 6.4 Rollback plan and its limits

| Scenario | Rollback | Limitation |
|---|---|---|
| Migration rollback | Drop the added columns/indexes | Safe **only if** no code/logic depends on them yet (flag still off) |
| Backfill rollback | `business_id = NULL` for rows written by a given `run_id` (via audit), restore `ownership_status='unassigned'` | **Cannot** reverse downstream effects already triggered by ownership (notifications, reports exported, payments recorded under the new owner, CRM campaigns) |
| Quarantine rollback | Resolve through reconciliation (`ownership_status='resolved'`) | Quarantine is derived from state (not stored); every transition must be logged, not silent |

> **Explicit limitation:** once ownership has influenced downstream actions (payments, statements, campaigns), a "rollback" of the column does **not** restore the prior operational state. This is why apply mode is restricted to `AUTO_RESOLVABLE` and gated by dual confirmation.

---

## 7. Required tests and measurable acceptance criteria

### 7.1 Required tests (when implementation is approved)

**Schema/FK:**
1. New columns exist, are nullable, FK `restrict`, indexes present.
2. Deleting a referenced business is blocked; archiving a business is allowed.

**Quarantine enforcement (flag on):**
3. `ownership_status != 'resolved'` rows are excluded from index/aging/statement/export.
4. Quarantined rows cannot be `show`/`pay`/`update` (tenant → 404; portal blocked).
5. Reconciliation actor with the permission can list and act on quarantined rows.
6. Flag off → legacy behavior unchanged (no filtering, no new denies).

**Manual assignment (FIN-02):**
7. Assignment requires membership + role + permission; unauthorized → 403.
8. Assignment sets `business_id` + `resolved` + audit entry.
9. Reassignment of a `resolved` record requires reason + step-up.
10. Assignment of a parent-conflicting record is rejected unless explicitly overridden (audited).
11. Context cannot be moved by request-supplied `business_id`.

**Backfill apply mode:**
12. Missing `--apply` → no writes.
13. Missing `--confirm-financial-ownership` → no writes.
14. Only `AUTO_RESOLVABLE` rows are written; manual/ambiguous/dangling untouched.
15. Already-`resolved` rows are skipped (idempotent).
16. Re-resolution at apply time: a row that changed to non-auto between dry-run and apply is skipped.
17. Batch failure rolls back only that batch; earlier batches persist and are audited.
18. Dry-run vs apply comparison detects any unexpected change and aborts.
19. Per-row audit entries carry a shared `run_id`.

**Report:**
20. Report remains read-only and unchanged by this work.

### 7.2 Measurable acceptance criteria

| Criterion | Threshold |
|---|---|
| Auto-written rows with a valid unique business | 100% of `AUTO_RESOLVABLE` at apply time |
| Non-auto rows mutated | **0** |
| Cross-business ownership drift | **0** |
| Idempotent re-run writes | **0** rows |
| Unresolved rows visible to tenant users | **0** |
| Migration reversibility (with flag off) | columns/indexes drop cleanly |
| Full test suite | all pass; legacy suite unchanged |
| Report vs apply mismatch | aborts before further writes |

---

## 8. Approval matrix — recommendations vs owner decisions

| # | Item | Type | Needs owner approval? |
|---|---|---|---|
| 1 | Nullable `payables.business_id` / `receivables.business_id` + FK `restrict` + indexes | [REC] | **Yes** (schema) |
| 2 | `ownership_status` / `ownership_evaluated_at` / `ownership_note` representation | [REC] | **Yes** (schema) |
| 3 | Quarantine = `ownership_status != 'resolved'`; exclusion in reads/mutations | [REC] | **Yes** (behavior) |
| 4 | New permissions (`financial-ownership-reconcile/assign/quarantine`) | [REC] | **Yes** (auth) |
| 5 | Manual-assignment rules + audit via `AuditLogService` | [REC] | **Yes** (workflow) |
| 6 | Reconciliation workflow | [REC] | **Yes** (workflow) |
| 7 | Production audit plan (read replica only) | [REC] | Operating permission |
| 8 | Dedicated backfill command + `--confirm-financial-ownership` | [REC] | **Yes** (write path) |
| 9 | Migration/backfill/validation/rollback plans | [REC] | **Yes** (execution) |
| 10 | Enabling `multi_business.enabled` | — | **Yes** (explicit, separate) |
| 11 | FK strategy `restrict` vs `nullOnDelete` | [REC] | **Yes** (choose one) |
| 12 | Whether hard-deleting businesses is ever allowed | [REC: no] | **Yes** |

---

## 9. Explicitly not authorized by this proposal

- No migration created or executed; no `business_id`/quarantine column added.
- No data changed; no apply mode run; no quarantine applied.
- No permission, policy, or workflow implemented.
- `multi_business.enabled` remains `false`; no deployment.
- No AUTH/SETTINGS/UNIQUE/scheduler implementation.

**Recommended immediate next action (no approval needed):** run the read-only report against an authorized read replica to replace the **UNKNOWN** production totals with real counts, then return here for the schema **[APPROVAL]**.

---

## 10. Design corrections & defect register (this revision)

Documentation-only revision. **No owner decision (FIN-01..05) was changed.** No schema/code was modified. The table lists defects found by re-inspecting the read-only report command, the resolver, the audit SQL, and the existing schema, and how each is addressed.

| ID | Severity | Defect | Resolution this revision |
|---|---|---|---|
| **D1** | High | Quarantine state machine listed six states but the diagram introduced a seventh `quarantined` value, and "terminal" contradicted FIN-05 (which allows reconciliation to resolve). | §2.1 rewritten: exactly six resolution states; quarantine is a **derived predicate** (`ownership_status != 'resolved'`) and is **not terminal**. Optional explicit 7th state presented as **[APPROVAL]** only. |
| **D2** | Medium | No explicit mapping between resolver `classification`/`reason_code` (evidence) and the stored `ownership_status`. | §2.1 mapping table added; reason codes are audit evidence only. |
| **D3** | High | New financial records (manual payable, checkout receivable) would default to `ownership_status='unassigned'` and thus be invisible/immutable under tenancy — no creation path sets `resolved`. | Added to §3.2/§7 tests; **requires code** (FIN-04/Q9.1) — separate **[APPROVAL]**. |
| **D4** | Medium | Proposed `restrictOnDelete` FK differs from every existing `business_id` FK in the repo (`nullOnDelete`). | Flagged in §1.2/§8; explicit owner choice required. |
| **D5** | Medium | Quarantining a parent must also exclude existing `payable_payments`/`receivable_payments` rows from tenant reads/statements. | Added requirement note in the runbook §C; **requires code** — separate **[APPROVAL]**. |
| **D6** | Medium | §6.4 rollback "via audit `run_id`" is not implementable with today's `business:backfill` (no run id, no per-row audit). | Runbook §C makes `run_id` + per-row audit a **precondition** of the proposed apply command; not built. |
| **D7** | Medium | Report JSON lacks provenance (DB identity, server version, args, schema fingerprint), weakening evidence retention. | Runbook §B captures provenance manually now; command-field addition is **[REC] code change**, separate approval. |
| **D8** | Low | `--format=json` combined with `--list` emits list lines before the JSON, producing invalid JSON. | Runbook §B forbids combining; command fix is **[REC] code change**, separate approval. |
| **D9** | Low | Command prints a hardcoded limitation "`ALREADY_RESOLVED` is currently unreachable", which becomes false once `business_id` exists. | Runbook §A notes it; make conditional is **[REC] code change**, separate approval. |
| **D10** | Low | Audit SQL §2.4 misses `warehouse.outlet_id` set but outlet unowned/missing; §3.1 `LEFT()` is MySQL-only. | Corrected in `multi-business-readonly-audit-queries.md` (this revision). |

**Pre-backfill safety checks** (backup/restore verification, concurrent-write
window, audit-log verification, batch-failure testing, idempotency, before/after
comparison, rollback rehearsal) are specified in
`financial-ownership-audit-runbook.md` §C and are **[REC] execution requirements**
pending owner approval.
