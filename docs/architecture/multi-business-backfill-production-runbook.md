# Multi-Business — Runbook Backfill Produksi

- **Status:** DRAFT — disiapkan, **BELUM dieksekusi**. Tidak ada data produksi yang diubah oleh dokumen ini.
- **Prasyarat eksekusi:** izin eksplisit pemilik proyek + DBA + jendela perawatan + backup terverifikasi (lihat §2).
- **Lingkup:** backfill `business_id` (master data, outlet/warehouse, blok CRM, tabel dokumen), settings per-business, membership, lalu **swap index unique global → composite** secara manual (termasuk `payables.document_number`; `transactions.invoice` global unique).
- **Referensi:** register keputusan §13.14 (SETTINGS/UNIQUE/SCHED/AUTH), §13.15 (memberships), §13.16 (onboarding), §13.17 (UNIQUE-08/09); `multi-business-readonly-audit-queries.md`; `financial-ownership-audit-runbook.md` (pola audit yang sama).

---

## 1. Keputusan yang mengikat runbook ini

| Keputusan | Isi | Konteks |
|---|---|---|
| Q7 | Seluruh instalasi existing = **satu business** (banyak branch) | satu `Business` default milik semua data legacy |
| Q5 / ADR-04 C | Settings: resolusi `outlet → business → global`; `PLATFORM_KEYS` tetap global | `business:settings-backfill` |
| AUTH-01/02/03 | Membership dari akses outlet; `owner`/`manager`/`staff`; super-admin **tanpa** membership (jalur platform) | `business:membership-backfill` |
| Q4 / UNIQUE | Identifier milik business → composite `(business_id, X)`; `units.code` global; `transactions.invoice` **global** (dibaca tanpa konteks business); UNIQUE-10 selesai (§13.20 + §13.21); UNIQUE-11 ditutup **global** (docs-only, §13.22) | swap index manual §8 |
| FIN-01 | Apply **wajib** didahului audit read-only + dual confirmation | gate §13 |

## 2. Pra-syarat infrastruktur & approval

- [ ] **Read replica / restored copy** produksi MySQL tersedia untuk Fase 0–2 (jangan jalan pada DB live).
- [ ] **Backup** lengkap + uji restore (point-in-time) di lingkungan terpisah; simpan bukti checksum.
- [ ] **Jendela perawatan** di luar jam operasional; aplikasi boleh `maintenance:down` saat apply (Fase 4).
- [ ] Approval tertulis: **pemilik proyek** + **DBA** untuk (a) eksekusi audit, (b) eksekusi apply, (c) swap index.
- [ ] Versi kode yang memuat command dipakai: `business:backfill`, `business:settings-backfill`, `business:membership-backfill` (tersedia sejak branch staging-ready; **tidak** perlu flag ON untuk memakainya — command bekerja terhadap kolom, bukan flag).
- [ ] `MULTI_BUSINESS_ENABLED` tetap `false` selama seluruh runbook **kecuali** fase QA staging terpisah (§10).

> Prinsip: **tidak pernah satu orang**. Setiap fase yang menulis data dijalankan oleh orang yang berbeda dari yang meng-approve, atau minimal direview ganda (checklist §13).

## 3. Ringkasan fase

| Fase | Tujuan | Mutasi data? | Gate |
|---|---|---|---|
| 0 — Audit read-only | Angka riil: orphan, duplikat, kepemilikan | Tidak | Owner + DBA |
| 1 — Deploy kode & migrasi | Schema `business_id` siap | Migrasi additive (guarded) | DBA |
| 2 — Planning (dry-run) | Plan pending/already/conflict per tabel | Tidak | Owner |
| 3 — Gate approval | Persetujuan tertulis apply | Tidak | Owner + DBA |
| 4 — Apply bertahap | Stempel `business_id` + memberships | **Ya** | Checklist §13 |
| 5 — Swap index manual | Composite unique aktif | **Ya** (DDL) | DBA |
| 6 — Verifikasi | Semua NULL = 0, duplikat tidak ada | Tidak | Owner |
| 7 — (Deferred) Aktivasi flag + QA | Tenancy menyala di staging | — | Keputusan terpisah |

---

## Fase 0 — Audit read-only (pada read replica / restored copy)

Sumber kueri: `multi-business-readonly-audit-queries.md`. Tambahan khusus runbook ini:

```sql
-- 0.1 Baris tanpa business_id per tabel business-owned (target backfill)
SELECT 'outlets' t, COUNT(*) c FROM outlets  WHERE business_id IS NULL
UNION ALL SELECT 'warehouses',  COUNT(*) FROM warehouses  WHERE business_id IS NULL
UNION ALL SELECT 'products',    COUNT(*) FROM products    WHERE business_id IS NULL
UNION ALL SELECT 'categories',  COUNT(*) FROM categories  WHERE business_id IS NULL
UNION ALL SELECT 'customers',   COUNT(*) FROM customers   WHERE business_id IS NULL
UNION ALL SELECT 'suppliers',   COUNT(*) FROM suppliers   WHERE business_id IS NULL
UNION ALL SELECT 'units',       COUNT(*) FROM units       WHERE business_id IS NULL
UNION ALL SELECT 'price_lists', COUNT(*) FROM price_lists WHERE business_id IS NULL
UNION ALL SELECT 'customer_segments',   COUNT(*) FROM customer_segments   WHERE business_id IS NULL
UNION ALL SELECT 'customer_campaigns',  COUNT(*) FROM customer_campaigns  WHERE business_id IS NULL
UNION ALL SELECT 'customer_vouchers',   COUNT(*) FROM customer_vouchers   WHERE business_id IS NULL;
```

```sql
-- 0.2 Baris yang sudah memiliki business_id (harus 0 — business belum ada; kalau > 0, STOP)
SELECT 'products' t, COUNT(*) c FROM products WHERE business_id IS NOT NULL
UNION ALL SELECT 'categories',  COUNT(*) FROM categories  WHERE business_id IS NOT NULL
UNION ALL SELECT 'customers',   COUNT(*) FROM customers   WHERE business_id IS NOT NULL
UNION ALL SELECT 'suppliers',   COUNT(*) FROM suppliers   WHERE business_id IS NOT NULL
UNION ALL SELECT 'units',       COUNT(*) FROM units       WHERE business_id IS NOT NULL
UNION ALL SELECT 'price_lists', COUNT(*) FROM price_lists WHERE business_id IS NOT NULL
UNION ALL SELECT 'warehouses',  COUNT(*) FROM warehouses  WHERE business_id IS NOT NULL
UNION ALL SELECT 'outlets',     COUNT(*) FROM outlets     WHERE business_id IS NOT NULL
UNION ALL SELECT 'customer_segments',  COUNT(*) FROM customer_segments  WHERE business_id IS NOT NULL
UNION ALL SELECT 'customer_campaigns', COUNT(*) FROM customer_campaigns WHERE business_id IS NOT NULL
UNION ALL SELECT 'customer_vouchers',  COUNT(*) FROM customer_vouchers  WHERE business_id IS NOT NULL;
```

```sql
-- 0.3 Settings tenant yang masih global (target business:settings-backfill)
SELECT 'global_tenant' t, COUNT(*) c
FROM settings
WHERE business_id IS NULL AND outlet_id IS NULL
AND key NOT IN ('app_setup_completed', 'setup_warehouse_id');
```

```sql
-- 0.4 Settings yang terikat outlet (harus menerima business dari outlet-nya)
SELECT COUNT(*) AS outlet_rows FROM settings WHERE outlet_id IS NOT NULL AND business_id IS NULL;
```

Perintah audit tambahan (baca-saja, tidak menulis):

```bash
php artisan inventory:reconcile          # laporkan selisih stok (report)
php artisan outlet:audit --strict        # audit outlet/warehouse, status kegagalan rollout
php artisan outlet:legacy-audit          # klasifikasi warehouse tanpa outlet (NO mutation)
php artisan business:backfill            # dry-run plan (tanpa --apply)
php artisan business:settings-backfill   # dry-run
php artisan business:membership-backfill # dry-run
```

**Kriteria lanjut:** 0.2 = 0; tidak ada duplikat dalam scope global untuk field ber-index (0 baris per query §1.1 audit queries); hasil 0.1/0.3 dicatat sebagai baseline.

## Fase 1 — Deploy kode & migrasi (pada jendela deploy normal)

1. Deploy kode branch staging-ready (bukan `main`).
2. `php artisan migrate --force` — migration bersifat **additive + data-guarded**:
   - `2026_10_10_160000` dst: menambah `business_id` nullable (semua baris lama tetap `NULL`).
   - `2026_10_12_000002` + `..._000003`: swap index unique ke composite **hanya** bila nol baris `NULL` (pada DB terisi tidak akan swap → index global dipertahankan). Ini **disengaja**: ø tidak ada constraint yang hilang secara diam-diam.
   - `2026_10_13_000001` (UNIQUE-10 dokumen): menambah `business_id` di enam tabel dokumen + swap unique global → composite **hanya** bila nol baris `NULL` (inert pada DB terisi → selesaikan di Fase 5).
   - `2026_10_13_000002` (sisa UNIQUE-10): `transactions.invoice` → **global** unique (guard: tidak ada invoice duplikat — di produksi invoice acak `TRX-*`, jadi umumnya auto-apply di Fase 1); `payables.document_number` → composite `(business_id, document_number)` (guard: seluruh baris sudah punya `business_id` **dan** tidak ada duplikat per business → pada DB terisi pre-backfill **tidak** swap, selesaikan di Fase 5).
   - `2026_10_12_000001` (settings): selalu mengganti unique menjadi `(key, business_id, outlet_id)`; duplikasi dicegah level aplikasi (upsert) — konsisten dengan catatan UNIQUE di register.
   - `2026_10_13_000003` (Q12 audit logs): menambah `business_id` nullable + index di `audit_logs` (additif; baris lama tetap `NULL`). Log legacy `NULL` **disembunyikan** di viewer saat flag ON; **tidak ada backfill otomatis** (§13.24) — backfill hanya opsional via command terpisah bila diperlukan.
3. Verifikasi `php artisan migrate:status` — semuanya `Ran`.
4. `php artisan business:backfill` dry-run di read replica untuk memastikan schema tidak error (hasil dicatat sebagai baseline plan).

> Command `business:backfill` sengaja **melewati** tabel tanpa kolom `business_id` (§13.17), sehingga aman jika ada DB yang belum termigrasi penuh — tetap, jangan bergantung pada itu.

## Fase 2 — Planning (dry-run, read replica)

```bash
# 2.1 Plan backfill master data — boleh jalan di live (read-only), pastikan TANPA --apply
php artisan business:backfill --create-default --name="NAMA_TOKO_DEFAULT"

# 2.2 Plan settings
php artisan business:settings-backfill --business=1 --confirm-legacy-ownership # no-op tanpa --apply, hanya menampilkan tabel

# 2.3 Plan memberships
php artisan business:membership-backfill
```

**Yang wajib dicatat dari output 2.1:** kolom `pending` per tabel harus sama dengan baseline Fase 0 (0.1); `conflict` harus 0. Bila ada `pending` > 0 pada tabel yang seharusnya 0, atau `conflict` > 0 → **STOP**, selidiki sebelum lanjut.

Catatan volume: `business:backfill --apply` membungkus seluruh stempel dalam **satu transaksi** — pada tabel besar bisa menahan lock lama. Jalankan dalam jendela perawatan; ukur durasi di restored copy dulu.

## Fase 3 — Gate approval (tanpa mutasi)

Checklist persetujuan tertulis (tanda tangan owner + DBA):

- [ ] Baseline Fase 0 ditinjau (tidak ada orphan/konflik).
- [ ] Plan Fase 2 sesuai baseline.
- [ ] Backup terverifikasi (restore test sukses).
- [ ] Nama business default disetujui (Q7: satu business untuk seluruh install).
- [ ] Daftar baris yang tidak ikut di-stempel (jika ada, mis. data pindahan manual) disepakati.
- [ ] Jendela perawatan & prosedur rollback (§12) disetujui.

## Fase 4 — Apply bertahap (live, pada jendela perawatan)

Urutan **wajib** (membership butuh business dulu; settings butuh business dulu):

```bash
# 4.1 Business + master data + outlet/warehouse + blok CRM
php artisan business:backfill --create-default --name="NAMA_TOKO_DEFAULT" \
  --apply --confirm-legacy-ownership

# 4.2 Settings per-business (outlet-inherit + global tenant → business target)
php artisan business:settings-backfill --business=1 --apply --confirm-legacy-ownership

# 4.3 Memberships dari akses outlet (owner/manager/staff; super-admin TANPA membership)
php artisan business:membership-backfill --apply --confirm-membership-backfill
```

Opsional verifikasi per-langkah sebelum lanjut:

```bash
php artisan business:backfill            # plan ulang → pending harus 0
php artisan business:settings-backfill   # dry-run → tidak ada global tenant tersisa
php artisan business:membership-backfill # dry-run → tidak ada yang akan dibuat lagi
```

Idempotensi: ketiga command idempotent — mengulang tidak menggandakan data.

## Fase 5 — Swap index manual ke composite (DBA, live)

Setelah Fase 4, semua baris memiliki `business_id` non-null → aman mengganti index unique global dengan composite. **`units.code` DIKECUALIKAN (UNIQUE-04 tetap global).**

```sql
ALTER TABLE products DROP INDEX products_barcode_unique,
  ADD UNIQUE INDEX products_business_barcode_unique (business_id, barcode);
ALTER TABLE products DROP INDEX products_sku_unique,
  ADD UNIQUE INDEX products_business_sku_unique (business_id, sku);
ALTER TABLE price_lists DROP INDEX price_lists_slug_unique,
  ADD UNIQUE INDEX price_lists_business_slug_unique (business_id, slug);
ALTER TABLE warehouses DROP INDEX warehouses_code_unique,
  ADD UNIQUE INDEX warehouses_business_code_unique (business_id, code);
ALTER TABLE outlets DROP INDEX outlets_code_unique,
  ADD UNIQUE INDEX outlets_business_code_unique (business_id, code);
ALTER TABLE customers DROP INDEX customers_member_code_unique,
  ADD UNIQUE INDEX customers_business_member_code_unique (business_id, member_code);
ALTER TABLE customer_vouchers DROP INDEX customer_vouchers_code_unique,
  ADD UNIQUE INDEX customer_vouchers_business_code_unique (business_id, code);
ALTER TABLE customer_segments DROP INDEX customer_segments_slug_unique,
  ADD UNIQUE INDEX customer_segments_business_slug_unique (business_id, slug);
-- UNIQUE-10 dokumen (migration 2026_10_13_000001, inert pada DB terisi)
ALTER TABLE purchase_orders DROP INDEX purchase_orders_document_number_unique,
  ADD UNIQUE INDEX purchase_orders_business_document_number_unique (business_id, document_number);
ALTER TABLE goods_receivings DROP INDEX goods_receivings_document_number_unique,
  ADD UNIQUE INDEX goods_receivings_business_document_number_unique (business_id, document_number);
ALTER TABLE supplier_returns DROP INDEX supplier_returns_document_number_unique,
  ADD UNIQUE INDEX supplier_returns_business_document_number_unique (business_id, document_number);
ALTER TABLE stock_transfers DROP INDEX stock_transfers_document_number_unique,
  ADD UNIQUE INDEX stock_transfers_business_document_number_unique (business_id, document_number);
ALTER TABLE sales_returns DROP INDEX sales_returns_code_unique,
  ADD UNIQUE INDEX sales_returns_business_code_unique (business_id, code);
ALTER TABLE stock_opnames DROP INDEX stock_opnames_code_unique,
  ADD UNIQUE INDEX stock_opnames_business_code_unique (business_id, code);
-- Sisa UNIQUE-10 (migration 2026_10_13_000002): payables composite + transactions global unique.
-- payables(): DROP tidak ada — legacy hanya index biasa (`payables_document_number_index`), biarkan untuk pencarian document_number.
ALTER TABLE payables ADD UNIQUE INDEX payables_business_document_number_unique (business_id, document_number);
ALTER TABLE transactions ADD UNIQUE INDEX transactions_invoice_unique (invoice);
```

> Pada instalasi yang dikerjakan sejak awal (tabel kosong saat migrasi) swap sudah terjadi otomatis; jalankan Fase 5 hanya untuk instalasi legacy. `settings` sudah di-swap oleh migration 000001, tidak termasuk di sini.
>
> **`transactions.invoice`:** guardnya hanya "tidak ada invoice duplikat" (bukan `business_id NULL`), jadi pada produksi yang invoice-nya acak `TRX-*` unique ini **umumnya sudah terpasang otomatis di Fase 1**. Blok di atas adalah fallback bila Fase 1 melewatinya (terdapat duplikat lama); cek `SHOW INDEX FROM transactions` dulu sebelum menambah. **`payables`:** composite **tidak** auto-apply pre-backfill (guard `business_id NULL`), jadi swap manual di sini wajib setelah Fase 4.

**Sebelum swap** jalankan (MySQL ≥ 8.0 dan InnoDB — gunakan `ALGORITHM=INPLACE, LOCK=NONE` bila memungkinkan):

```sql
-- Sanity: bila ada baris dengan business_id NULL, jangan swap (STOP).
SELECT COUNT(*) FROM products WHERE business_id IS NULL; -- dan semua tabel di atas (termasuk payables + 6 tabel dokumen) → wajib 0
-- payables: pastikan juga tidak ada duplikat (business_id, document_number) sebelum composite.
SELECT business_id, document_number, COUNT(*) c FROM payables GROUP BY business_id, document_number HAVING c > 1;
-- transactions: pastikan tidak ada invoice duplikat sebelum ADD UNIQUE.
SELECT invoice, COUNT(*) c FROM transactions GROUP BY invoice HAVING c > 1;
```

Rollback swap: lihat §12 (baris Fase 5).

## Fase 6 — Verifikasi pasca-apply (read-only)

```bash
php artisan business:backfill          # plan → semua pending = 0, conflict = 0
php artisan business:settings-backfill # tidak ada global tenant tersisa
php artisan business:membership-backfill
php artisan inventory:reconcile        # tidak ada selisih baru
php artisan outlet:audit --strict
```

```sql
-- 6.1 Pastikan nol baris NULL (harus 0 untuk setiap baris hasil)
SELECT 'products' t, COUNT(*) c FROM products WHERE business_id IS NULL
UNION ALL SELECT 'categories',  COUNT(*) FROM categories     WHERE business_id IS NULL
UNION ALL SELECT 'customers',   COUNT(*) FROM customers      WHERE business_id IS NULL
UNION ALL SELECT 'suppliers',   COUNT(*) FROM suppliers      WHERE business_id IS NULL
UNION ALL SELECT 'units',       COUNT(*) FROM units          WHERE business_id IS NULL
UNION ALL SELECT 'price_lists', COUNT(*) FROM price_lists    WHERE business_id IS NULL
UNION ALL SELECT 'warehouses',  COUNT(*) FROM warehouses     WHERE business_id IS NULL
UNION ALL SELECT 'outlets',     COUNT(*) FROM outlets        WHERE business_id IS NULL
UNION ALL SELECT 'customer_segments',  COUNT(*) FROM customer_segments  WHERE business_id IS NULL
UNION ALL SELECT 'customer_campaigns', COUNT(*) FROM customer_campaigns WHERE business_id IS NULL
UNION ALL SELECT 'customer_vouchers',  COUNT(*) FROM customer_vouchers  WHERE business_id IS NULL;

-- 6.2 Duplikat per scope composite tidak boleh ada (harus 0 baris)
SELECT business_id, barcode, COUNT(*) c FROM products GROUP BY business_id, barcode HAVING c > 1;
SELECT business_id, slug, COUNT(*) c FROM customer_segments GROUP BY business_id, slug HAVING c > 1;
SELECT business_id, code, COUNT(*) c FROM customer_vouchers GROUP BY business_id, code HAVING c > 1;
SELECT business_id, document_number, COUNT(*) c FROM payables GROUP BY business_id, document_number HAVING c > 1;
SELECT invoice, COUNT(*) c FROM transactions GROUP BY invoice HAVING c > 1;
-- (lanjutkan untuk sku, price_lists.slug, warehouses.code, outlets.code, customers.member_code,
--  dan document_number/code PO/GR/SR/ST/sales_returns/stock_opnames)

-- 6.3 Membership
SELECT COUNT(*) FROM business_user; -- bandingkan dengan jumlah akses outlet pemilik/manager/staff (tanpa super-admin)
```

**Smoke test staging (terpisah):** salin DB produksi pasca-apply ke staging, aktifkan `MULTI_BUSINESS_ENABLED=true`, jalankan QA manual `docs/testing-manual.md` + akses demo `manager`/`staff`/`super-admin` (`DemoFlagOnAccessTest` sebagai referensi). **Jangan** aktifkan flag di produksi tanpa langkah ini.

## Fase 7 — (Deferred) Aktivasi flag di produksi

- Keputusan terpisah (bukan bagian runbook ini): kapan `MULTI_BUSINESS_ENABLED=true`, bump `APP_VERSION`, deploy, dan QA produksi.
- Checklist manual QA: `docs/testing-manual.md`; verifikasi scheduling per business (SCHED-01/02) dan overlap offline sync.

---

## 12. Rollback

| Fase | Tindakan |
|---|---|
| 0–2 | Tidak ada data ditulis; cukup hentikan. |
| 4.1 | Restore dari backup (stempel `business_id` dibalik via `UPDATE <tabel> SET business_id = NULL` hanya jika backup tidak tersedia — jangan manual bila ada backup). |
| 4.2 | Restore `settings.business_id = NULL` pada baris yang diubah (backup) lalu hapus baris business-scoped yang dibuat oleh migrasi settings. |
| 4.3 | Hapus baris `business_user` (backup). |
| 5 | Balik index composite → global (daftar terbalik §8): `ALTER TABLE ... DROP INDEX <composite>, ADD UNIQUE INDEX <legacy> (<col>);` — untuk `payables`/`transactions` cukup `DROP INDEX payables_business_document_number_unique` / `DROP INDEX transactions_invoice_unique` (tidak ada legacy unique). |
| 6+ | Bila ada anomali → restore backup + jalankan ulang verifikasi. |

**Rehearsal wajib** pada restored copy sebelum eksekusi live: jalankan Fase 4–6, ukur durasi, verifikasi hasil identik.

## 13. Checklist approval akhir

```text
[ ] Fase 0 baseline disetujui (owner + DBA)
[ ] Fase 1 migrasi di monitor; status: semua Ran
[ ] Fase 2 plan = baseline; conflict = 0
[ ] Fase 3 persetujuan tertulis apply (owner + DBA)
[ ] Fase 4 apply berhasil; pending = 0 (dry-run ulang)
[ ] Fase 5 swap index selesai & terverifikasi SHOW INDEX
[ ] Fase 6 verifikasi; nol NULL; smoke staging flag ON sukses
[ ] Fase 7 aktivasi produksi = keputusan terpisah (BELUM dilakukan)
```

## 14. Catatan batas

- Dokumen ini **tidak** mengubah data apa pun; hanya panduan yang menunggu approval.
- Flag `multi_business.enabled` tetap `false` di produksi sampai keputusan terpisah.
- **Bukan bagian runbook ini:** demo seeder di produksi (dilarang), UI pembuatan business tambahan (Q6), dan perubahan `TenantOwnership` (verifikasi kepemilikan tetap via outlet, §13.17). UNIQUE-11 sudah ditutup **global** (docs-only, §13.22) sehingga tidak menghasilkan langkah DDL apa pun di runbook ini.