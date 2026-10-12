# Multi-Business — Read-Only Audit Queries (Duplicates, Orphans, Ownership)

- **Status:** Prepared, **not executed**. No database was queried during the phase that produced this file.
- **Scope:** Workstream D (Q4 duplicate/uniqueness audit) and Workstream E (Q7/Q9/Q15 financial orphan/dangling-parent audit).
- **Safety:** Every statement below is `SELECT`-only. There are **no** `UPDATE`/`DELETE`/`INSERT`/DDL statements. Do not run these against production unless explicitly authorised; run against a read replica or a restored copy where possible.
- **Ownership is never assigned here.** These queries only classify and count rows. Deciding an owner is an explicit business decision (see `multi-business-blocker-review-and-decision-register.md`).

> **Execution status:** **Not run.** The repository test suite uses an ephemeral in-memory SQLite database, which cannot represent production data volumes or legacy rows. No safe local copy of the operational database was available in this environment. Every count below is therefore **unverified** and must be produced by an authorised operator.

---

## 1. Q4 — Duplicate detection per uniqueness candidate

Fields that already have a **global** unique index cannot contain duplicates, so their queries should return zero rows. Fields **without** a DB index (`categories.name`, `customers.no_telp`) are the ones where duplicates may actually exist.

### 1.1 Fields with a global unique index (expected: 0 rows)

```sql
-- products.barcode / products.sku
SELECT barcode, COUNT(*) AS c FROM products GROUP BY barcode HAVING c > 1;
SELECT sku,     COUNT(*) AS c FROM products GROUP BY sku     HAVING c > 1;

-- price_lists.slug
SELECT slug, COUNT(*) AS c FROM price_lists GROUP BY slug HAVING c > 1;

-- units.code / warehouses.code / outlets.code
SELECT code, COUNT(*) AS c FROM units      GROUP BY code HAVING c > 1;
SELECT code, COUNT(*) AS c FROM warehouses GROUP BY code HAVING c > 1;
SELECT code, COUNT(*) AS c FROM outlets    GROUP BY code HAVING c > 1;

-- customers.member_code
SELECT member_code, COUNT(*) AS c FROM customers
WHERE member_code IS NOT NULL GROUP BY member_code HAVING c > 1;
```

### 1.2 Fields without a DB unique index (real duplicate risk)

```sql
-- categories.name — global duplicates
SELECT name, COUNT(*) AS c FROM categories GROUP BY name HAVING c > 1;

-- categories.name — duplicates inside one business (the tenant-scoped scope)
SELECT business_id, name, COUNT(*) AS c
FROM categories
GROUP BY business_id, name
HAVING c > 1;

-- customers.no_telp — duplicates inside one business
SELECT business_id, no_telp, COUNT(*) AS c
FROM customers
WHERE no_telp IS NOT NULL AND no_telp <> ''
GROUP BY business_id, no_telp
HAVING c > 1;
```

### 1.3 Cross-business value collisions that the current global index forbids

These show values that a per-business scope (Q4) would allow but the global index blocks today. They are informational only and must not drive a migration before Q4 approval.

```sql
-- barcodes that appear under more than one business_id (currently impossible if index intact)
SELECT barcode, COUNT(DISTINCT business_id) AS businesses
FROM products GROUP BY barcode HAVING businesses > 1;

-- NULL business_id rows that a composite index would treat as distinct
SELECT 'products' AS tbl, COUNT(*) AS null_business FROM products WHERE business_id IS NULL
UNION ALL SELECT 'categories', COUNT(*) FROM categories WHERE business_id IS NULL
UNION ALL SELECT 'customers',  COUNT(*) FROM customers  WHERE business_id IS NULL
UNION ALL SELECT 'suppliers',  COUNT(*) FROM suppliers  WHERE business_id IS NULL
UNION ALL SELECT 'units',      COUNT(*) FROM units      WHERE business_id IS NULL
UNION ALL SELECT 'price_lists',COUNT(*) FROM price_lists WHERE business_id IS NULL
UNION ALL SELECT 'outlets',    COUNT(*) FROM outlets    WHERE business_id IS NULL
UNION ALL SELECT 'warehouses', COUNT(*) FROM warehouses WHERE business_id IS NULL;
```

> **Backfill must precede composite indexes.** On MySQL a composite `(business_id, <col>)` treats `NULL business_id` as distinct, so duplicates with `business_id IS NULL` would slip through. See ADR-03 §4.

---

## 2. Q7/Q9/Q15 — Financial orphan / dangling-parent inventory

### 2.1 Payables without a purchase order (manual)

```sql
SELECT COUNT(*) AS manual_payables
FROM payables
WHERE purchase_order_id IS NULL;
```

### 2.2 Receivables without a transaction (manual)

```sql
SELECT COUNT(*) AS manual_receivables
FROM receivables
WHERE transaction_id IS NULL;
```

### 2.3 Dangling parents (parent row no longer exists)

Foreign keys are enforced by the application migrations, so these should be 0 unless data was imported/repaired outside the app. Run anyway to confirm integrity.

```sql
-- payable -> purchase_orders
SELECT p.id, p.document_number, p.purchase_order_id
FROM payables p
LEFT JOIN purchase_orders po ON po.id = p.purchase_order_id
WHERE p.purchase_order_id IS NOT NULL AND po.id IS NULL;

-- receivable -> transactions
SELECT r.id, r.invoice, r.transaction_id
FROM receivables r
LEFT JOIN transactions t ON t.id = r.transaction_id
WHERE r.transaction_id IS NOT NULL AND t.id IS NULL;
```

### 2.4 Parents that cannot be attributed to a business (ambiguous ownership)

```sql
-- Payable whose chain is missing/broken or has no business attribution.
-- w.id IS NULL                -> dangling.warehouse_missing
-- o.business_id IS NULL below -> warehouse unowned, or outlet unowned/missing
--                                (ambiguous.unowned_warehouse / dangling.outlet_missing)
SELECT p.id AS payable_id, po.id AS po_id, po.warehouse_id,
       w.business_id AS warehouse_business, w.outlet_id,
       o.id AS outlet_id, o.business_id AS outlet_business
FROM payables p
JOIN purchase_orders po ON po.id = p.purchase_order_id
LEFT JOIN warehouses w ON w.id = po.warehouse_id
LEFT JOIN outlets o ON o.id = w.outlet_id
WHERE w.id IS NULL
   OR (w.business_id IS NULL AND o.business_id IS NULL);

-- Receivable whose chain is missing/broken or has no business attribution.
SELECT r.id AS receivable_id, t.id AS transaction_id, t.warehouse_id,
       w.business_id AS warehouse_business, w.outlet_id,
       o.id AS outlet_id, o.business_id AS outlet_business
FROM receivables r
JOIN transactions t ON t.id = r.transaction_id
LEFT JOIN warehouses w ON w.id = t.warehouse_id
LEFT JOIN outlets o ON o.id = w.outlet_id
WHERE w.id IS NULL
   OR (w.business_id IS NULL AND o.business_id IS NULL);
```

> **Correction (2026-10-11):** the previous version tested `w.outlet_id IS NULL` only,
> so a warehouse whose `outlet_id` pointed at an unowned or missing outlet was not
> counted. The query now joins `outlets` and tests `o.business_id IS NULL`, matching
> the resolver's `ambiguous.unowned_warehouse` / `dangling.outlet_missing` cases.
> `w.id IS NULL` covers `dangling.warehouse_missing`.

### 2.5 Conflicting ownership signals

`purchase_orders` has **no** `business_id` column (verified in this repository), so the only ownership conflict on the chain is between a warehouse and its outlet.

```sql
-- Warehouse business_id disagrees with its outlet's business_id
SELECT w.id AS warehouse_id, w.business_id AS warehouse_business,
       o.id AS outlet_id, o.business_id AS outlet_business
FROM warehouses w
JOIN outlets o ON o.id = w.outlet_id
WHERE w.business_id IS NOT NULL AND o.business_id IS NOT NULL
  AND w.business_id <> o.business_id;
```

### 2.6 Node resolution and missing intermediate links

```sql
-- Payables whose chain is complete and resolves to exactly one business (auto-resolvable)
SELECT p.id AS payable_id, w.id AS warehouse_id,
       COALESCE(w.business_id, o.business_id) AS resolved_business
FROM payables p
JOIN purchase_orders po ON po.id = p.purchase_order_id
JOIN warehouses w ON w.id = po.warehouse_id
LEFT JOIN outlets o ON o.id = w.outlet_id
WHERE COALESCE(w.business_id, o.business_id) IS NOT NULL
  AND NOT (w.business_id IS NOT NULL AND o.business_id IS NOT NULL AND w.business_id <> o.business_id);

-- Receivables whose chain is complete and resolves to exactly one business
SELECT r.id AS receivable_id, w.id AS warehouse_id,
       COALESCE(w.business_id, o.business_id) AS resolved_business
FROM receivables r
JOIN transactions t ON t.id = r.transaction_id
JOIN warehouses w ON w.id = t.warehouse_id
LEFT JOIN outlets o ON o.id = w.outlet_id
WHERE COALESCE(w.business_id, o.business_id) IS NOT NULL
  AND NOT (w.business_id IS NOT NULL AND o.business_id IS NOT NULL AND w.business_id <> o.business_id);

-- Purchase orders whose warehouse is null (chain breaks above the payable)
SELECT id, document_number, warehouse_id FROM purchase_orders WHERE warehouse_id IS NULL;

-- Transactions whose warehouse is null (chain breaks above the receivable)
SELECT id, invoice, warehouse_id FROM transactions WHERE warehouse_id IS NULL;
```

> **Engine note.** These statements are written for both MySQL and SQLite. `COALESCE` and standard joins behave the same; `LEFT JOIN ... IS NULL` is used for dangling detection rather than engine-specific syntax. Production runs MySQL; do not assume SQLite planner/index behaviour matches MySQL for large tables.

> **Ownership is never assigned here.** These queries only classify and count. Automatic assignment (FIN-01) is limited to the complete, conflict-free chains above; everything else fails closed and requires manual handling.

---

## 3. Q5 — Settings reader/writer snapshot (before any scope change)

```sql
-- Global rows (outlet_id IS NULL) that are shared by every business today.
-- `key` is quoted because KEY is a reserved word in MySQL; backticks are also
-- accepted by SQLite, so this form runs on both engines.
SELECT `key`, outlet_id, SUBSTR(value, 1, 120) AS value_preview, updated_at
FROM settings
WHERE outlet_id IS NULL
ORDER BY `key`;

-- Outlet-scoped rows.
SELECT `key`, outlet_id, COUNT(*) AS c
FROM settings
WHERE outlet_id IS NOT NULL
GROUP BY `key`, outlet_id
ORDER BY `key`, outlet_id;
```

> **Correction (2026-10-11):** `LEFT(value, 120)` was MySQL-only; replaced with
> `SUBSTR(value, 1, 120)`, which is valid on MySQL and SQLite. The `key` column is
> now backtick-quoted (`KEY` is reserved in MySQL; SQLite accepts backticks).

Keys observed written through the global accessor in code (verify against the result above before deciding scope):

```
store_npwp, store_nib, tax_default_rate,
discount_approval_threshold, discount_approval_percent_threshold,
loyalty_enable_earn, loyalty_enable_redeem, loyalty_earn_rate_amount,
loyalty_redeem_point_value, loyalty_tier_regular_threshold,
loyalty_tier_silver_threshold, loyalty_tier_gold_threshold,
loyalty_tier_platinum_threshold
```

---

## 4. How to run safely (operator checklist)

1. Use a **read replica** or a restored non-production copy; never point at the live primary. Full procedure: `financial-ownership-audit-runbook.md`.
2. Run each query individually and record `(query id, row count, timestamp, database identity)`.
3. Capture database identity explicitly: `@@hostname`, `@@server_id`, `DATABASE()`, `VERSION()`, `@@read_only` (must be `1`), and the replication/binlog position.
4. Do not paste results that contain customer phone numbers, NPWP/NIB, or other PII into tickets; store counts only, or mask values.
5. Treat every count as **unverified** until reproduced by the authorised operator.

> **Engine identifier quoting.** Several columns are reserved words on MySQL
> (e.g. `settings`.`key`). Quote them (`` `key` ``; SQLite also accepts backticks)
> and prefer `SUBSTR(...)` over `LEFT(...)` so the statements run on both MySQL and
> SQLite. The `collect` query in §1.2 already relies on `categories.business_id`
> and `customers.business_id`, which exist via
> `2026_10_10_160000_add_business_id_to_master_data_tables.php`.
