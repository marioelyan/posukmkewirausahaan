# Financial Ownership — Production Audit Runbook & Compatibility Verification (FIN-01)

- **Status:** **Documentation + non-mutating inspection only.** No database was contacted; no migration, schema, data, command, or flag change was made.
- **Date:** 2026-10-11
- **Scope:** FIN-01 read-only production-data audit preparation, engine/schema compatibility verification, operational runbook, pre-backfill safety checks, and restored-copy provisioning/handoff (Part E).
- **Related:** `financial-ownership-schema-and-operations-design.md` (§10 defect register), `financial-ownership-resolution-design.md`, `financial-ownership-dry-run-report.md`, `multi-business-readonly-audit-queries.md`, `app/Console/Commands/FinancialOwnershipReportCommand.php`, `app/Support/Tenancy/FinancialOwnershipResolver.php`.
- **Owner decisions:** unchanged (FIN-01/Q7.1, FIN-02/Q7.2, FIN-03/Q7.3, FIN-04/Q9.1, FIN-05/Q15.1).

> **Production counts remain UNKNOWN.** No authorized read replica or restored
> copy was available in this environment. I did **not** connect to or query any
> database. The figures in `financial-ownership-dry-run-report.md` are
> fixture-only and must never be substituted for production counts.

---

## Part A — Compatibility verification (actual engine + schema)

Verified by reading migrations, models, and the command/resolver source. **No DB connection.**

### A.1 Engine facts

| Fact | Value | Source |
|---|---|---|
| Production driver | **MySQL** (`DB_CONNECTION=mysql`) | `.env.example`, `.env` |
| Test driver | **SQLite in-memory** | `phpunit.xml` (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) |
| MySQL strict mode | enabled (`'strict' => true`) | `config/database.php` |
| FK enforcement | enabled (SQLite `DB_FOREIGN_KEYS` default true) | `config/database.php` |

Implication: the audit SQL must run on **MySQL** in production, but should also be
reviewable/executable on a SQLite restored copy without rewriting. See A.4.

### A.2 Report command compatibility — PASS (with caveats)

`php artisan business:financial-ownership-report` was inspected and its `--help` was
executed (boots the app; **no DB query**). Findings:

| Check | Result |
|---|---|
| Registered on the default connection | Yes (`business:financial-ownership-report`) |
| Read-only by construction (no `--apply`, no write path) | Yes (asserted by test `test_report_command_has_no_apply_option`, `test_report_command_does_not_mutate_any_data`) |
| Tables it reads exist | `payables`, `receivables`, `purchase_orders`, `transactions`, `warehouses`, `outlets` — all present |
| `chunkById` preconditions | Both financial tables have an integer `id` PK — OK on MySQL and SQLite |
| Uses engine-specific SQL | No — Query Builder + `Schema::hasTable`/`hasColumn` only |
| Requires `business_id` on financial tables | No — `existingBusinessId()` guards with `Schema::hasColumn` |
| Works with tenancy flag on or off | Yes (asserted by tests) |

**Caveats found (documented as defects D7–D9 in the design §10; command unchanged):**

- **C1 — JSON purity:** `--format=json` **combined with `--list`** emits per-record
  list lines (via `$this->line()`) before the JSON payload, so the captured file is
  **not valid JSON**. Operators must never combine the two flags. A code fix
  (buffer list output and emit it to stderr, or reject the combination) is a
  separate **[REC] code change** pending approval.
- **C2 — Stale limitation text:** the JSON `limitations` array hardcodes
  "`ALREADY_RESOLVED` is currently unreachable". This becomes false once
  `payables.business_id`/`receivables.business_id` exist. Make it conditional on
  `Schema::hasColumn` — separate **[REC] code change**.
- **C3 — Missing provenance:** the JSON does not include DB identity, server
  version, database name, command arguments, or a schema fingerprint. Provenance
  must currently be captured out-of-band (Part B). Adding fields is a separate
  **[REC] code change**.
- **C4 — Query volume (performance):** `resolve()` issues up to ~4 queries per
  record (record → parent → warehouse → outlet), and the command re-fetches each
  record by id even though `chunkById` already supplied it. On a large `payables`/
  `receivables` table this is O(rows) round-trips. Plan capacity; prefer `--table`
  one at a time and a static copy.
- **C5 — No consistent snapshot:** the command does not wrap the scan in a
  transaction, so on a live replica each statement sees a different read view.
  For authoritative numbers use a **static restored copy** (Part B.0).

### A.3 Resolver compatibility — PASS

| Check | Result |
|---|---|
| `payables` → `purchase_orders.purchase_order_id` | Present, nullable FK `nullOnDelete` |
| `receivables` → `transactions.transaction_id` | Present, nullable FK `nullOnDelete` |
| `purchase_orders.warehouse_id`, `transactions.warehouse_id` | Present, nullable |
| `warehouses.business_id`, `warehouses.outlet_id` | Present, nullable |
| `outlets.business_id` | Present, nullable |
| Conflict comparison `(int) warehouse.business_id !== (int) outlet.business_id` | Portable across MySQL/SQLite |
| `existingBusinessId()` degrades safely when the column is absent | Yes |

The ownership graph assumed by the resolver matches the schema. The blanket
`business:backfill` command does **not** touch financial tables (verified in source:
its map is `products, categories, customers, suppliers, units, price_lists, outlets, warehouses`).

### A.4 Audit SQL compatibility — PARTIAL (corrections applied in `multi-business-readonly-audit-queries.md`)

| ID | Defect | Correction |
|---|---|---|
| **S1** | §3.1 used `LEFT(value, 120)`, a **MySQL-only** function; SQLite needs `SUBSTR`. The doc's §2 engine note implies both engines. | Replaced with `SUBSTR(value, 1, 120)` (valid on MySQL and SQLite). |
| **S2** | §3.1 selects the column literally named `key` unquoted; `KEY` is a **MySQL reserved word**. | Quoted as `` `key` `` with a note to use `"key"` on SQLite. |
| **S3** | §2.4 "ambiguous" query missed the case where `warehouse.outlet_id` is set but the outlet is unowned or missing (`w.outlet_id IS NULL` only). The resolver classifies that as `ambiguous.unowned_warehouse`/`dangling.outlet_missing`. | §2.4 rewritten to `LEFT JOIN outlets` and test `(w.business_id IS NULL AND o.business_id IS NULL)`. |
| **S4** | No SQL parity for `dangling.outlet_missing`; §2.3 covers only payable→PO and receivable→transaction. | Added to the corrected §2.4 join (missing outlet ⇒ `o.business_id IS NULL`); §2.6 remains the authoritative AUTO count. |

The §2.6 "node resolution" AUTO query was re-derived against the resolver and
**matches** it: inner-join parents/warehouse, `LEFT JOIN` outlet, `COALESCE`
non-null, and exclusion of the warehouse/outlet conflict.

### A.5 Compatibility verdict

- **Report command:** engine-agnostic and schema-safe; runs on MySQL (production)
  and SQLite (restored copy). Caveats C1–C5 are non-blocking for a read-only audit
  but require operator discipline (Part B).
- **Resolver:** compatible with the verified schema.
- **Audit SQL:** now portable after S1–S4 corrections.

---

## Part B — Operational runbook (read replica / restored copy only)

> **Never run any step of this runbook against the live primary.** The only
> authorized targets are (a) a static restored copy, or (b) a read-only replica.

### B.0 Target selection & authorization

1. Obtain **written authorization** naming the target environment and confirming it
   is a read replica or restored copy (not production primary).
2. **Preferred target: a static restored copy** produced with a consistent dump
   (`mysqldump --single-transaction --source-data=2` on MySQL 8.0.26+; use
   `--master-data=2` on older MySQL — or a filesystem/xtrabackup snapshot). This
   removes replica-lag and mid-scan-change ambiguity (C5) and makes counts reproducible.
3. If only a live replica is available, record the replication position before and
   after (B.1) and re-run to detect drift (B.5).

### B.1 Environment identification & connection verification

Run these with the **same credentials the app will use**, via the `mysql` client
(not the app), so there is no ambiguity:

```sql
-- identity + read-only state (MySQL 8 / MariaDB)
SELECT @@hostname AS hostname, @@server_id AS server_id, @@port AS port,
       DATABASE() AS db, VERSION() AS version,
       @@read_only AS read_only, @@super_read_only AS super_read_only;

-- replication position (MySQL 8; also keep for a live-replica run)
SELECT @@global.gtid_executed AS gtid_executed;
SHOW REPLICA STATUS\G        -- MySQL 8.0.22+ ("SHOW SLAVE STATUS" on older/MariaDB)
-- MySQL 8.0.x: SHOW MASTER STATUS;  MySQL 8.4+: SHOW BINARY LOG STATUS;
```

Pass criteria:

- `read_only = 1` and (where the variable exists) `super_read_only = 1`.
- On a replica: `Replica_IO_Running = Yes`, `Replica_SQL_Running = Yes`; record
  `Seconds_Behind_Source` and `Exec_Source_Log_Pos`.
- `@@server_id` / `@@hostname` **differ from the documented primary**.
- If any check fails, **stop** — do not run the report.

Configure the app to use this target via a dedicated env file (git-ignored), e.g.
`.env.fin01-replica`, and confirm the app never points at the primary:

```dotenv
APP_ENV=fin01-replica
DB_CONNECTION=mysql
DB_HOST=<replica-host>
DB_DATABASE=<database>
DB_USERNAME=fin01_ro
DB_PASSWORD=<secret>
SESSION_DRIVER=array
CACHE_STORE=array
QUEUE_CONNECTION=sync
APP_DEBUG=false
```

```bash
# ensure no stale cached config points elsewhere (local bootstrap cache only)
php artisan config:clear --env=fin01-replica
# sanity check: prints help without running the query
php artisan business:financial-ownership-report --env=fin01-replica --help
```

### B.2 Read-only safeguards

1. **Dedicated DB user with SELECT only** (defence in depth even though the command
   is read-only):

   ```sql
   CREATE USER 'fin01_ro'@'%' IDENTIFIED BY '<secret>';
   GRANT SELECT, SHOW VIEW ON `<database>`.* TO 'fin01_ro'@'%';
   -- guarantee absence of write grants
   REVOKE INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES
     ON `<database>`.* FROM 'fin01_ro'@'%';
   FLUSH PRIVILEGES;
   ```

2. Keep `read_only=1` on the server; keep the app on `SESSION_DRIVER=array`,
   `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`.
3. Run **only** the read-only report under this env. Do **not** run
   `business:backfill`, `migrate`, `db:seed`, or any queue worker under the replica env.
4. Confirm from source (already asserted by tests) that the report has no `--apply`
   option and performs no writes.

### B.3 Execution

Run each table separately so a failure or timeout affects only one artifact, and
so JSON files stay small:

```bash
ART=./artifacts/fin01; mkdir -p "$ART"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"; TARGET="<replica-server-id or copy-id>"

# provenance block first
mysql -h <host> -u fin01_ro -p<secret> -N -e \
  "SELECT @@hostname, @@server_id, DATABASE(), VERSION(), @@read_only;" \
  > "$ART/fin01-$TARGET-$STAMP-db-identity.txt"

php artisan business:financial-ownership-report --env=fin01-replica \
  --table=payables --format=json > "$ART/fin01-$TARGET-$STAMP-payables.json"

php artisan business:financial-ownership-report --env=fin01-replica \
  --table=receivables --format=json > "$ART/fin01-$TARGET-$STAMP-receivables.json"

# optional identifiers — SEPARATE files, NEVER combined with --format=json (defect C1)
php artisan business:financial-ownership-report --env=fin01-replica \
  --table=payables --list --max-ids=100000 > "$ART/fin01-$TARGET-$STAMP-payables-list.txt"
```

Also capture the raw row totals used for reconciliation:

```bash
mysql -h <host> -u fin01_ro -p<secret> -N -e \
  "SELECT 'payables', COUNT(*) FROM payables UNION ALL SELECT 'receivables', COUNT(*) FROM receivables;" \
  > "$ART/fin01-$TARGET-$STAMP-rowcounts.txt"
```

### B.4 Output capture & evidence retention

- Store all artifacts under an **access-controlled** location; the JSON contains no
  PII or amounts, but `--list` files contain record ids (still treat as internal).
- Record, alongside the artifacts: authorization reference, operator identity,
  UTC timestamp, target identity, replication/binlog position, command lines, and
  the app/env used.
- Integrity: `sha256sum "$ART"/* > "$ART/SHA256SUMS"`.
- Retention: keep at least until the backfill is signed off and for the audit
  window defined by the owner. Do not attach raw `--list` output to tickets if it
  could expose ids; prefer counts.

### B.5 Count reconciliation

For **each** table artifact:

1. `total` in the JSON equals `SELECT COUNT(*)` from `rowcounts.txt`.
2. `sum(by_classification[].count) == total`.
3. `sum(by_reason values) == total`.
4. All `by_classification` keys are within the known six:
   `AUTO_RESOLVABLE, MANUAL_ASSIGNMENT_REQUIRED, AMBIGUOUS_OWNERSHIP,
   DANGLING_OR_DELETED_PARENT, ALREADY_RESOLVED, INVALID_OR_UNSUPPORTED_CHAIN`.
   Any unknown key ⇒ **stop**.
5. Cross-check against the corrected SQL in `multi-business-readonly-audit-queries.md`:
   - `AUTO` vs §2.6 node-resolution query;
   - `MANUAL` vs §2.1 (payables) / §2.2 (receivables);
   - `AMBIGUOUS` vs corrected §2.4;
   - `DANGLING` vs §2.3 plus missing-intermediate checks;
   - `ALREADY` = 0 pre-schema; post-schema equals rows that already had `business_id`.
6. Global identity: `payables.total + receivables.total == grand total`.
7. Stability: re-run once and compare (live replica); any drift means the target is
   not stable ⇒ switch to a static restored copy and repeat.

### B.6 STOP / no-go conditions

Stop and escalate (do **not** proceed to any backfill) if:

- any target is the primary, or `read_only != 1`;
- totals do not reconcile per B.5;
- any unknown classification/reason key appears;
- the report JSON is invalid (e.g. flags combined per C1);
- the replica is lagging or the two runs disagree.

### B.7 One-page summary template

```
FIN-01 production ownership audit
Target:            <replica server_id / copy id>   (authorized ref: ____)
Captured (UTC):    ____________________   Operator: __________
Engine/version:    MySQL ____ / SQLite ____
DB / app env:      <database> / fin01-replica
Replication pos:   gtid=____ file=____ pos=____  lag=____s   (n/a for static copy)

payables:    total=____ AUTO=____ MANUAL=____ AMBIGUOUS=____ DANGLING=____ UNSUPPORTED=____ ALREADY=____
receivables: total=____ AUTO=____ MANUAL=____ AMBIGUOUS=____ DANGLING=____ UNSUPPORTED=____ ALREADY=____
by_reason totals reconcile:  YES / NO
SQL cross-check (AUTO/MANUAL/AMBIGUOUS/DANGLING):  MATCH / MISMATCH (detail ____)
Stability re-run:            IDENTICAL / DRIFTED
Artifacts sha256:            <path>/SHA256SUMS   (hash: ____)

Conclusion: counts verified / NOT verified. If verified, forward to owner for
schema [APPROVAL]. No backfill is authorized by this runbook.
```

---

## Part C — Pre-backfill safety checks

These are **execution requirements [REC]** for any future backfill; they are not
performed now and require separate owner approval. Every check must be rehearsed
on a **restored copy** before production.

### C.1 Backup & restore verification

- Take a fresh logical backup of the primary (or a consistent replica snapshot):
  `mysqldump --single-transaction --source-data=2 --routines --events ...`.
- Restore it into a scratch database and verify, before any write:
  - row counts of `payables`, `receivables`, `payable_payments`,
    `receivable_payments` match;
  - checksums of financial tables match (`CHECKSUM TABLE` or a deterministic
    aggregate);
  - the restore completes within the agreed maintenance window.
- Record the backup id/hash and restore timestamp as the rollback point.

### C.2 Concurrent-write considerations

- The proposed apply command sets `business_id` only on `AUTO_RESOLVABLE` rows and
  re-resolves live inside the transaction, so new rows created after the dry-run
  are handled deterministically.
- Still, plan a **quiescence/maintenance window** (or a read-only application
  window) to avoid:
  - a payable/receivable being created *during* the scan (checkout / goods
    receiving) and landing outside the dry-run snapshot;
  - parent/warehouse/outlet edits changing a row's classification between dry-run
    and apply.
- If writes cannot be paused, run with a small `--limit`, verify between stages,
  and re-run the report after each stage.
- Never enable `multi_business.enabled` during backfill; ownership writes must not
  become user-visible until enforcement is separately approved.

### C.3 Audit-log verification

- Confirm `audit_logs` exists and `AuditLogService::log()` works on the restored
  copy.
- **[REC] precondition:** the apply command must write one audit entry **per row**
  (event `financial.ownership_backfill_applied`) carrying a shared `run_id`,
  `before`/`after` `business_id` + `ownership_status`, and `meta{reason_code}` — in
  the **same transaction** as the row update.
- Rationale: this is the only mechanism that makes a run-specific rollback
  (D6) and after-the-fact reconciliation possible. The current `business:backfill`
  does **not** do this; the financial command must.

### C.4 Batch-failure testing (on the restored copy)

- Simulate a mid-batch failure (kill the DB connection, force a constraint error,
  or inject an exception) and verify:
  - only the **current** batch rolls back;
  - previously committed batches persist and remain audited;
  - re-running resumes and completes without duplicate ownership;
  - partial-run state is detectable from `run_id` audit entries.

### C.5 Idempotency

- Run apply twice on the restored copy. The second run must:
  - write **0** rows (guarded by `business_id IS NULL`);
  - exit `0`;
  - produce no new audit `run_id`.
- Re-run the read-only report: every previously written AUTO row is now
  `ALREADY_RESOLVED` with the same business; no other row changed.

### C.6 Before/after comparison

- Capture before: per-table counts by classification, `by_reason`, and the
  auto-resolvable **business distribution**.
- Capture after: the same, plus per-business aging/statement totals.
- Assert:
  - `AUTO` rows moved to `ALREADY_RESOLVED` only for the businesses proven by the
    chain;
  - no amount moved between businesses (aging totals per business unchanged);
  - `MANUAL`/`AMBIGUOUS`/`DANGLING`/`UNSUPPORTED` counts unchanged;
  - total row counts unchanged.

### C.7 Rollback rehearsal

- On the restored copy, rehearse the rollback: set `business_id = NULL` and
  `ownership_status = 'unassigned'` for rows written under a given `run_id`, and
  verify counts return to the pre-apply baseline.
- Document the **irreversible** part: once ownership has influenced downstream
  actions (payments, statements, campaigns), nulling the column does not restore
  operational state (see design §6.4).

### C.8 Additional invariants to verify

- **Parent quarantine propagation (D5):** a quarantined payable/receivable must
  also hide its `payable_payments`/`receivable_payments` from tenant reads and
  statements.
- **New-record state (D3):** records created under an active business context must
  be written with `business_id` and `ownership_status='resolved'` (FIN-04); confirm
  the creation paths (manual payable, checkout receivable) do this before enforcing
  tenancy.
- **FK target validity:** every proposed `business_id` exists and is `active`;
  `restrictOnDelete` (if chosen) does not block any planned operation.
- **Flag stays off:** `multi_business.enabled` remains `false` throughout the
  backfill.

---

## Part D — Defect register & approvals

See the design proposal §10 "Design corrections & defect register" for the
canonical, numbered list (D1–D10) and the separation between:

- **Fixed in documentation this revision:** D1 (state machine), D2 (mapping),
  D10 (audit SQL portability/coverage).
- **Requires code [REC] / separate approval:** D3 (new-record state), D5 (payment
  propagation), D7 (provenance fields), D8 (JSON+list), D9 (stale limitation),
  D6 (run-id audit precondition).
- **Requires an explicit owner choice:** D4 (`restrictOnDelete` vs `nullOnDelete`).

No migration, schema change, data mutation, permission/workflow, apply-mode code,
deployment, or `multi_business.enabled` activation is authorized by this runbook.

---

## Part E — Restored-copy provisioning & operator handoff

This part supplies the DBA/operator provisioning and sign-off that Part B
(execution) assumes. It **references** B/C rather than repeating them.

### E.1 Minimum prerequisites (all required before running)

| # | Prerequisite | Requirement | Where |
|---|---|---|---|
| P1 | Written approval + target identity | Sign-off naming the target and confirming it is a **restored copy / read replica, not the primary** | recorded in B.7 |
| P2 | Compatible DB version & schema | Same MySQL **major** version as production; schema at the same migration set as the approved backup | E.2 step 4 |
| P3 | SELECT-only account | Dedicated user with `SELECT`/`SHOW VIEW` only; no write/DDL grants | B.2 |
| P4 | Server identity verified | `@@hostname`/`@@server_id` differ from primary; `read_only=1` | B.1 |
| P5 | Isolated restored copy | No route from the app/queue/scheduler fleet; no write path to the primary | E.2/E.3 |
| P6 | Artifact location | Access-controlled path **outside the repo**; JSON per table | B.3/B.4 |
| P7 | Evidence retention | Retention window + `sha256` + provenance fields | B.4 |
| P8 | Responsible operator | Named operator **and** DBA, recorded | B.7 |
| P9 | Secret management | Credential in the approved secret manager; never via chat/ticket | E.4 |

Any unmet prerequisite ⇒ **STOP / BLOCKED**.

### E.2 DBA provisioning checklist (isolated restored copy)

1. Identify the **approved backup**: id/hash, creation time, source, migration set. Record it.
2. Provision an **isolated** MySQL host: separate network/security group, **no route to the production primary**, not reachable from any application/queue/scheduler node — only the operator host.
3. Restore with a consistent method (`mysqldump --single-transaction ...` or xtrabackup); use the same MySQL **major** version as production.
4. Verify schema compatibility (read-only; compare to the backup manifest):
   ```sql
   SELECT VERSION();
   SELECT COUNT(*) AS applied_migrations, MAX(batch) AS last_batch FROM migrations;
   ```
   Mismatch ⇒ **STOP**.
5. Set `read_only=1` (and `super_read_only=1` where supported).
6. Create the SELECT-only account (B.2); store its secret in the approved secret manager (E.4).
7. Ensure no application side effects can reach it (E.3).
8. Record target identity and hand it to the operator with the authorization ref.
9. After the audit: keep the copy until the report is signed off, then **destroy/decommission** it and record the destruction (for a live replica, remove the temporary operator grant).

### E.3 Isolation guarantees to verify before running

- **Not the primary:** `@@server_id`/`@@hostname` differ from the documented primary; `read_only=1`.
- **No write path:** SELECT-only account **and** server `read_only`.
- **No application side effects:** no app server/worker/scheduler has network access to the target; the app is pointed at it only via the temporary operator env.
- **Only the read-only report is run** — never `migrate`, `db:seed`, `business:backfill`, queue workers, or `serve` (B.2). The report has no `--apply`.
- Local-only writes (`storage/logs`, bootstrap cache) are acceptable but must never touch the DB.

### E.4 Secret management

- **Never** transmit DB credentials via chat, email, or tickets.
- Store the SELECT-only credential in the **approved secret manager**; materialize it into a git-ignored env file at runtime with mode `0600`.
- ⚠️ **Repo gap (verified):** `.gitignore` ignores only `.env`, `.env.backup`, `.env.production` — a `.env.fin01-replica` is **not** ignored. The operator must add an ignore rule **or** keep the file outside the repository. (Not changed here; would be a repo-config edit.)
- Revoke/rotate the credential after the audit and record it.

### E.5 Operator handoff (sign-off block)

```
FIN-01 restored-copy read-only audit — handoff
Authorization ref:          __________
Target (name/id):           __________   server_id=____  hostname=____
DB version / migration set: __________   matches backup manifest? YES/NO
Isolation verified:         primary=NO   read_only=YES   no-app-route=YES
SELECT-only account:        yes   secret manager path: __________
Artifact path (outside repo): __________   retention until: __________
Responsible operator:       __________   DBA: __________
Date (UTC):                 __________

Execution:       follow Part B.3 (per-table JSON; never combine --list with --format=json)
Storage:         follow Part B.4 (sha256, provenance, retention)
Reconciliation:  follow Part B.5 (totals / by-reason / known keys / SQL cross-check / stability)
Summary:         complete the Part B.7 template
Secrets:         Part E.4 (never via chat; revoke after)
```

**Do not record credentials, secret values, or personal data in this block.**
