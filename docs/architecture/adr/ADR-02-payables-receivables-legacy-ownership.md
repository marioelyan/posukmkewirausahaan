# ADR-02 — Legacy Ownership untuk Payable & Receivable (Q7 / Q9 / Q15)

- **Status:** Proposed — **menunggu persetujuan pemilik proyek**.
- **Tanggal audit:** 2026-10-11
- **Basis bukti:** kode + skema aktual repository.
- **Terkait:** `docs/architecture/multi-business-data-ownership-matrix.md`, `docs/architecture/multi-business-phase-4e-implementation-notes.md`, `multi-business-open-decisions.md` (Q7/Q9/Q15).
- **Catatan:** Tidak ada `business_id`, tidak ada perubahan skema, dan tidak ada backfill yang dilakukan oleh dokumen/fase ini.

## 1. Konteks

`payables` dan `receivables` tidak memiliki `business_id` maupun `warehouse_id`. Kepemilikannya harus diturunkan dari relasi induk:

- `payables.purchase_order_id` → `purchase_orders` → `warehouse_id` → `warehouses.outlet_id` → `outlets.business_id`
- `receivables.transaction_id` → `transactions` → `warehouse_id` → `warehouses.outlet_id` → `outlets.business_id`

Record manual/legacy tidak punya induk, sehingga kepemilikannya tidak dapat dibuktikan.

## 2. Kondisi aktual yang ditemukan

### 2.1 Skema (FAKTA)

- `payables` (`database/migrations/2026_01_02_000000_create_suppliers_and_payables_tables.php`): `supplier_id` (nullable), `document_number` (index, **bukan unique**), `total`, `paid`, `due_date`, `status`, `note`. Tidak ada `business_id`/`warehouse_id`.
- `payables.purchase_order_id` ditambahkan `2026_05_04_000003_add_purchase_order_id_to_payables_table.php` (nullable, `nullOnDelete`).
- `receivables` (`2026_01_01_000000_create_receivables_tables.php`): `customer_id` (nullable), `transaction_id` (nullable, `nullOnDelete`), `invoice` (index), `total`, `paid`, `due_date`, `status`, `note`. Tidak ada `business_id`/`warehouse_id`.
- Tabel pembayaran: `payable_payments`, `receivable_payments` (child via cascade, `bank_account_id` nullable).

### 2.2 Resolusi ownership saat ini (FAKTA)

- `app/Support/Tenancy/TenantOwnership.php` — `VIA_PARENT_TABLES`:
  - `'payables' => ['purchase_orders', 'purchase_order_id']`
  - `'receivables' => ['transactions', 'transaction_id']`
  - `entityBelongsToActiveBusiness()` membaca FK induk; **bila FK null → `return false` (fail closed)** saat tenancy aktif.
- `app/Rules/BelongsToActiveBusiness.php` memakai resolver yang sama.

### 2.3 Jalur penciptaan data (FAKTA)

- Payable dengan induk: `app/Services/GoodsReceivingService::createOrUpdatePayable()` (`updateOrCreate(['purchase_order_id' => $order->id], …)`). **Ter-owned**, karena `purchase_order_id` terisi.
- Payable manual: `app/Http/Controllers/Apps/PayableController::store()` — `Payable::create($data)`, **tanpa** `purchase_order_id`. `supplier_id` divalidasi `BelongsToActiveBusiness('suppliers')`. **Tidak ter-owned** (tidak ada induk).
- Receivable dengan induk: `app/Services/CheckoutService.php:134` — `Receivable::create([... 'transaction_id' ...])`. **Ter-owned**.
- Receivable manual: tidak ada endpoint `store` di `ReceivableController` (hanya `index/show/pay/aging/statement`). Record manual hanya mungkin berasal dari data legacy/import.
- `SupplierReturnController::store()` — `payable_id` divalidasi `BelongsToActiveBusiness('payables')` → payable manual/asing ditolak saat tenancy aktif.

### 2.4 Jalur baca (FAKTA — dan gap)

- `PayableController::index()`:
  - `PayableController::scopeQuery()` membatasi via `whereHas('purchaseOrder', whereIn warehouse_id, $warehouseIds)`.
  - **Fallback manual**: `orWhereDoesntHave('purchaseOrder')` **hanya** bila `Outlet::active()->count() <= 1`.
- `PayableAgingService::getAgingSummary()` / `scopeQuery()` mengikuti pola manual yang sama.
- `ReceivableService::scope()`: `whereHas('transaction', whereIn warehouse_id, $warehouseIds)`, fallback manual `orWhereDoesntHave('transaction')` bila `singleOutlet()` (`Outlet::active()->count() <= 1`).
- **Gap:** Under tenancy, fallback `orWhereDoesntHave(...)` memasukkan record tanpa induk (ownership tak terbukti) ke daftar/statement/rekap, **tanpa** pembatas business, ketika jumlah outlet aktif global ≤ 1. Ini adalah kebocoran baca lintas-business untuk record manual, walaupun sempit (butuh ≤1 outlet aktif global).
- `PayableController::ensureAccess()` / `ReceivableController::ensureAccess()` memakai `canUseWarehouse(?Warehouse)`; untuk record manual `warehouse = null` → `canUseWarehouse(null)` → `legacySingleOutletBypass()` (`config('security.outlet.legacy_single_outlet_bypass', true)` **dan** `Outlet::active()->count() <= 1`). Jadi show/pay record manual juga bisa lolos ketika ≤1 outlet aktif.
- **Dead code (FAKTA, bukan leak aktif):** `PayableAgingService::getDueSoonPayables()` dan `getTopSuppliersByPayable()` tidak dipanggil dari mana pun (`grep` di `app/`). Tidak ada jalur baca aktif.

## 3. Matriks jenis record dan cara menentukan ownership

| Jenis record | Sumber | Induk | Ownership dapat dibuktikan? | Perilaku tenancy saat ini | Perilaku yang diinginkan (rekomendasi) |
|---|---|---|---|---|---|
| Payable dari penerimaan PO | `GoodsReceivingService` | `purchase_order_id` → PO | Ya | Ter-owned, terlihat & bisa dibayar | Tetap |
| Payable manual | `PayableController::store` | — | Tidak | Disembunyikan kecuali ≤1 outlet aktif; bisa lolos bypass | **Fail closed** sampai ada `business_id`/backfill |
| Receivable dari checkout | `CheckoutService` | `transaction_id` → transaction | Ya | Ter-owned | Tetap |
| Receivable manual/legacy | Data lama/import | — | Tidak | Fallback manual bila ≤1 outlet aktif | **Fail closed** sampai ada `business_id`/backfill |
| `payable_payments` / `receivable_payments` | Pembayaran | `payable_id`/`receivable_id` | Mengikuti induk | Ikut induk | Tetap |

## 4. Dampak pada read dan write

- **Read (list/aging/statement):** record ber-induk aman. Record manual dapat bocor lintas business pada kondisi ≤1 outlet aktif, atau (lebih umum) memperlihatkan record milik business lain yang record-nya dibuat saat tenancy nonaktif (legacy).
- **Write (pay):** record manual dapat dibayar bila `canUseWarehouse(null)` lolos. Record ber-induk dibatasi lewat warehouse.
- **Konsistensi:** daftar (scopeQuery) dan akses (ensureAccess) memakai basis yang sama (`Outlet::active()->count() <= 1`) sehingga sebagian besar konsisten, tetapi keduanya sama-sama longgar untuk record manual.

## 5. Alternatif desain

| Opsi | Deskripsi | Keamanan | Operasional | Biaya | Risiko data |
|---|---|---|---|---|---|
| **1. Parent-only (fail closed)** | Ownership hanya lewat induk; record manual disembunyikan/tidak bisa diubah saat tenancy aktif | Tinggi (fail closed) | Record manual jadi tidak terlihat sampai di-backfill | Rendah | Record manual "hilang" dari UI sampai direkonsiliasi |
| **2. `business_id` eksplisit** | Tambah kolom nullable + backfill dari induk; manual butuh business terpilih | Tinggi | Jelas, manual terdukung | Sedang (migrasi + backfill) | Perlu strategi backfill & validasi |
| **3. Hybrid (rekomendasi)** | Ownership tetap dari induk sebagai sumber utama; `business_id` nullable untuk record tanpa induk | Tinggi | Manual tetap terdukung setelah backfill | Sedang | Backfill harus diaudit |
| **4. Legacy-tolerant** | Pertahankan fallback ≤1 outlet | Rendah | Tidak berubah | Nol | Kebocoran lintas-business tetul saat ≤1 outlet |

## 6. Rekomendasi

1. **Prinsip:** ownership dari induk yang tervalidasi adalah sumber utama (options 1+3). Record ambigu **fail closed** saat tenancy aktif.
2. **Jangka panjang:** opsi **Hybrid** — tambah `payables.business_id` & `receivables.business_id` (nullable) dengan backfill dari induk, lalu record manual baru mewajibkan business terpilih. Perlu persetujuan Q7/Q9/Q15.
3. **Jangan** memperluas `legacy_single_outlet_bypass` atau mengubah interpretasi data legacy tanpa persetujuan.
4. **Keputusan yang diminta:** apakah record manual boleh tetap ada (butuh kolom/backfill), atau harus fail closed permanen?

## 7. Penanganan record ambigu

- Saat tenancy aktif dan record tidak punya induk valid → **fail closed**: tidak tampil, tidak bisa diubah/dibayar.
- Saat tenancy nonaktif (legacy) → perilaku lama dipertahankan.
- Jangan menebak: jangan menganggap record tanpa induk milik business tertentu.

## 8. Kebutuhan migration / backfill (DRAFT — tidak dibuat, tidak dijalankan)

1. `ALTER TABLE payables ADD business_id BIGINT UNSIGNED NULL` + FK + index.
2. `ALTER TABLE receivables ADD business_id BIGINT UNSIGNED NULL` + FK + index.
3. Backfill `business_id` dari induk:
   - `UPDATE payables p JOIN purchase_orders po ON po.id = p.purchase_order_id JOIN warehouses w ON w.id = po.warehouse_id JOIN outlets o ON o.id = w.outlet_id SET p.business_id = o.business_id WHERE p.purchase_order_id IS NOT NULL`.
   - Analog untuk `receivables` via `transactions`.
4. Inventaris record yang tetap `business_id IS NULL` → daftar untuk keputusan manual (Q7).
5. Validasi: jumlah `WHERE business_id IS NULL` harus sama dengan jumlah record tanpa induk (audit).
6. **Tidak ada migration yang dibuat/dijalankan pada fase ini.** Struktur di atas menunggu persetujuan.

## 9. Strategi validasi sebelum backfill

- `php artisan outlet:audit --strict` (read-only) untuk memastikan semua outlet/warehouse punya business.
- Query hitung record tanpa induk vs dengan induk.
- Bandingkan dengan laporan payable/receivable existing sebelum & sesudah (dry-run).

## 10. Rollback & rekonsiliasi

- Rollback: `dropColumn business_id` (aman, kolom nullable baru) selama belum dipakai di unique/logika write.
- Rekonsiliasi: setelah backfill, bandingkan total aging summary per business dengan laporan sebelum migrasi.

## 11. Persetujuan yang masih dibutuhkan

- **Q7:** Strategi backfill business untuk data legacy.
- **Q9:** Apakah record manual diizinkan dan bagaimana penetapan business-nya.
- **Q15:** Perlakuan record legacy tanpa induk (hapus, arsip, atau biarkan fail closed).

## 12. Status implementasi pada fase ini

- **Tidak ada perubahan kode** untuk ADR-02.
- Fail-closed ownership lewat induk sudah berlaku (Build 4E, `TenantOwnership::VIA_PARENT_TABLES`).
- Gap fallback ≤1 outlet didokumentasikan sebagai **needs approval** karena terikat pada kebijakan legacy bypass (`security.outlet.legacy_single_outlet_bypass`, default `true`) dan definisi ownership data legacy (Q7/Q9/Q15). Mengubahnya sendirian akan menimbulkan ketidakkonsistenan antara daftar dan akses, dan menyentuh kebijakan keamanan yang belum disetujui.
