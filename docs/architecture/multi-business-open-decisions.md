# Multi-Business Open Decisions — Point of Sales

- **Status:** PERENCANAAN / DOKUMENTASI ONLY — keputusan yang telah disetujui: **Q6 = A (admin platform only)** via register §13.19 (2026-10-13), **Q4/UNIQUE-10 (nomor dokumen per business)** via register §13.20/§13.21 (2026-10-13), **Q11/UNIQUE-11 (idempotency token global)** via register §13.22 (2026-10-13), **pass status Q10–Q16** via register §13.23 (2026-10-13), dan **Q12 (`audit_logs.business_id`)** diimplementasikan via register §13.24 (2026-10-13). Q1–Q9 di tabel di bawah masih berstatus awal namun keputusannya sudah terwujud di register §13.14–§13.17 (§5).
- **Basis:** audit `multi-business-tenant-audit.md` §14 + temuan tambahan pada 2026-10-10.
- **Label:** **FAKTA**, **REKOMENDASI**, **ASUMSI**, **PERLU KEPUTUSAN**.
- Dokumen terkait: `multi-business-backend-implementation-plan.md`, `multi-business-data-ownership-matrix.md`, `multi-business-security-test-plan.md`.

> **Aturan.** Kecuali **Q6** (disetujui 2026-10-13, §13.19 register), tidak ada item di bawah ini berstatus "approved". Setiap item punya *default rekomendasi* yang dipakai **hanya sementara** agar perencanaan bisa dilanjutkan, dan *status penghambat* (blocking) untuk fase mana.

---

## 1. Ringkasan Status Keputusan

| ID | Pertanyaan singkat | Rekomendasi default | Menghambat | Status |
|---|---|---|---|---|
| Q1 | Definisi transaksi "completed" | `payment_status='paid'` | Fase 6 | PERLU KEPUTUSAN |
| Q2 | Owner global vs membership; super-admin tetap global | Membership `owner`; super-admin global | Fase 1-2 | PERLU KEPUTUSAN |
| Q3 | Role berbeda per business | Ya, role di membership | Fase 1 | PERLU KEPUTUSAN |
| Q4 | Master data terpisah penuh | Ya, terpisah penuh | Fase 4 | SELESAI (§13.14/§13.17 + UNIQUE-10 §13.20/§13.21 + UNIQUE-11 §13.22) |
| Q5 | Setting global vs per business | Hybrid key-based | Fase 1-3 | PERLU KEPUTUSAN |
| Q6 | Pembuatan business | Admin platform + self-service owner (fase lanjut) | Fase 8 | SELESAI (bagian admin platform; self-service deferred) |
| Q7 | Outlet existing = satu business | Satu business default | Fase 3 | PERLU KEPUTUSAN |
| Q8 | Background job di shared hosting | Sinkron + scheduler per menit | Fase 7 | PERLU KEPUTUSAN |
| Q9 | Data existing tanpa ownership jelas | Ke business default, ditandai | Fase 3 | PERLU KEPUTUSAN |
| Q10 | Status modul DineIn | Hapus (modul sudah tidak ada) | — | SELESAI (§13.23 — modul dihapus; migrasi drop shim) |
| Q11 | `client_uuid` unik | Global (keputusan §13.22) | — | SELESAI (§13.22 — global unique, docs-only) |
| Q12 | `audit_logs.business_id` | Tambahkan (nullable) | Fase 2 | SELESAI (§13.24 — kolom + stamp + scoping viewer) |
| Q13 | Global scope vs eksplisit | Eksplisit dulu, global scope selektif | Fase 2 | SELESAI (§13.23 — trait global scope selektif fail-closed + eksplisit) |
| Q14 | Strategi role Spatie teams vs membership | Membership dulu | Fase 1-2 | SELESAI (§13.23 — membership, `teams=false`) |
| Q15 | Penanganan `warehouses.outlet_id IS NULL` | Business default + audit | Fase 3 | SELESAI (§13.23 — outlet_id nullable + backfill PUSAT + audit read-only) |
| Q16 | Laporan lintas business (owner) | Isolasi per business aktif; tanpa agregasi | Fase 6 | SELESAI (§13.23 — scoped via outlet access + business aktif) |

---

## 2. Detail Keputusan

### Q1 — Definisi transaksi "completed"
- **FAKTA.** Tidak ada kolom `status`; hanya `payment_status` (`paid`, `unpaid`, `pending`, `pending_approval`, `failed`). Dashboard menghitung semua baris (`DashboardController.php:44`); shift benar memakai `paid` (`CashierShiftService.php:88,97`).
- **REKOMENDASI.** Completed = `payment_status='paid'`. `unpaid` = piutang (bukan omzet selesai). `pending`/`pending_approval` dikecualikan sampai final. `failed` dikecualikan.
- **Alternatif.** Tambah kolom `status` terpisah dari pembayaran (lebih bersih untuk order lifecycle, tetapi migrasi lebih besar).
- **Dampak.** Laporan omzet/laba, dashboard, sales report.
- **Blocking:** Fase 6.

### Q2 — Owner & super-admin
- **REKOMENDASI.** `Owner` adalah role **membership** bisnis, bukan role global. `super-admin` tetap global sebagai operator platform (existing `User::isSuperAdmin()` bypass).
- **Alternatif.** `owner` dijadikan role Spatie global (ambigu lintas business).
- **Dampak.** Model membership, policy, UI.
- **Blocking:** Fase 1-2.

### Q3 — Role berbeda per business
- **REKOMENDASI.** Boleh berbeda; role disimpan di `business_user`.
- **Alternatif.** Satu role global per user (tidak fleksibel untuk SaaS).
- **Dampak.** Evaluasi permission per konteks.
- **Blocking:** Fase 1.

### Q4 — Isolasi master data
- **REKOMENDASI.** Produk/kategori/customer/supplier/unit/price list terpisah penuh per business.
- **Alternatif.** Katalog bersama + override per business (kompleks; menambah kebocoran).
- **Dampak.** POS, impor/ekspor, laporan.
- **Status (2026-10-12):** composite unique per-business SELESAI untuk `products` (barcode/sku), `price_lists` (slug), `warehouses`/`outlets` (code), `customers` (member_code), `customer_vouchers` (code) dan `customer_segments` (slug); `units.code` tetap global (UNIQUE-04).
- **Status (2026-10-13):** UNIQUE-10 (nomor dokumen per business) SELESAI — enam tabel dokumen (PO/GR/SR/ST + `sales_returns.code` + `stock_opnames.code`) mendapat `business_id` + trait + generator per business + swap composite kondisional (register §13.20). Format nomor tetap `PREFIX-YYYYMMDD-####` dengan urutan per business. Sisa UNIQUE-10 juga SELESAI (register §13.21): `payables.document_number` = composite `(business_id, document_number)`, `transactions.invoice` = **global** unique (dibaca tanpa konteks business; per-business justru ambigu), plus validasi `TenantUnique` di `PayableController`. UNIQUE-11 juga ditutup (register §13.22): `client_uuid` **global unique** (sudah ada) + `context_key` global dengan namespacing per business. Detail: register §13.14/§13.17/§13.20/§13.21/§13.22.
- **Blocking:** — (UNIQUE-10 §13.20/§13.21; UNIQUE-11 ditutup global §13.22).

### Q5 — Setting global vs per business
- **FAKTA.** `settings` sudah punya `outlet_id` (`2026_09_14_000004`). Key belum diklasifikasi.
- **REKOMENDASI.** Klasifikasi hybrid: `app_setup_completed`, versi, dan default platform = global; `store_name`, logo, gateway, PPN = per business/outlet.
- **Alternatif.** Semua setting per business (duplikasi nilai platform).
- **Dampak.** Onboarding, setup wizard, `HandleInertiaRequests`.
- **Blocking:** Fase 1-3.

### Q6 — Pembuatan business
- **REKOMENDASI.** Fase awal: hanya admin platform membuat business. Self-service signup owner ditambahkan belakangan dengan kontrol abuse.
- **Alternatif.** Self-service langsung (risiko spam/penyalahgunaan).
- **Dampak.** Route onboarding, `registration.enabled`, `EnsureNotInstalled`.
- **Status (2026-10-12):** bootstrap first-install SELESAI — setup wizard membuat business default + scope saat `multi_business.enabled=true`, admin setup (super-admin) memakai jalur platform (AUTH-01). Yang masih menunggu keputusan: pembuatan business **tambahan** (UI/rollout) dan self-service owner signup. *(dijawab 2026-10-13 — lihat KEPUTUSAN di bawah)*.
- **KEPUTUSAN (2026-10-13):** **A. Admin platform only** — §13.19 register. Super-admin membuat business tambahan via dashboard (`dashboard/businesses*`, middleware `platform.admin`, `step_up`); provisioning via `BusinessProvisioner` (skeleton PUSAT + owner opsional, tanpa `assignUnscoped`); UI `Businesses/{Index,Create}` + item sidebar (tampil saat flag ON + super-admin). Self-service owner signup tetap fase lanjut. UNIQUE-10 (dokumen) dituntaskan §13.20/§13.21; UNIQUE-11 ditutup global §13.22.
- **Blocking:** Fase 8 (sisa: self-service owner signup + aktivasi flag/deployment; bagian admin-platform SELESAI §13.19).

### Q7 — Outlet existing = satu atau banyak business
- **REKOMENDASI.** Seluruh instalasi existing dianggap **satu business** (banyak branch) → business default.
- **Alternatif.** Pemecahan per outlet (butuh pemisahan data manual yang berisiko).
- **Dampak.** Seluruh backfill.
- **Blocking:** Fase 3.

### Q8 — Background job di shared hosting
- **FAKTA.** Tidak ada `app/Jobs`; scheduler ada di `routes/console.php`; `schedule:run` per menit.
- **REKOMENDASI.** Tetap sinkron/scheduler; tidak mengasumsikan queue worker/Redis/WebSocket. Command wajib iterasi per business.
- **Alternatif.** Queue worker (butuh infra tidak tersedia di shared hosting).
- **Dampak.** CRM, reorder, expire transaksi.
- **Blocking:** Fase 7.

### Q9 — Data existing tanpa ownership jelas
- **REKOMENDASI.** Assign ke business default, tandai lewat `outlet:legacy-audit`/`outlet:audit`, rekonsiliasi stok.
- **Dampak.** Integritas backfill.
- **Blocking:** Fase 3.

### Q10 — Status modul DineIn
- **FAKTA.** Modul DineIn **sudah dihapus penuh** dari kode (model, service, route, page, dan create-migration tidak ada lagi). Sisa satu berkas: `database/migrations/2026_10_11_000001_drop_dine_in_tables.php` — `dropIfExists` untuk `dine_order_items`/`dine_orders`/`dine_tables`/`dine_areas`, no-op pada install fresh, membersihkan install lama yang masih punya tabel.
- **KEPUTUSAN (2026-10-13):** **hapus modul** — register §13.23. Migrasi drop dipertahankan sebagai **shim idempotent** sampai semua install ter-deploy; penghapusan berkas **opsional pasca-deploy** (nilai rendah, aman bila ditahan).
- **Blocking:** —

### Q11 — `client_uuid` unik
- **FAKTA.** Sudah **global unique** di DB (`2026_09_10_000001:12`); lookup idempotency business-scoped (`PosApiController.php:743,878`).
- **KEPUTUSAN (2026-10-13):** **global unique** (bukan per-business) — register §13.22. Race double-insert sudah tertutup + ada test; per-business hanya menambah kolom `business_id` di `transactions` tanpa manfaat praktis. Dampak: tanpa perubahan kode.
- **Blocking:** —

### Q12 — `audit_logs.business_id`
- **FAKTA.** Tabel `audit_logs` (`2026_05_02_120000`) semula **tidak** punya `business_id`; `AuditLogService::log()` menyimpan `user_id` + `module`/`auditable*` tetapi bukan business; tidak ada scoping per business pada log audit.
- **KEPUTUSAN (2026-10-13):** **SELESAI (§13.24)** — tambah `business_id` **nullable** + index (`2026_10_13_000003`), stamp dari `BusinessContext` saat flag ON (server-side) di `AuditLogService`, dan scoping viewer `AuditLogController` per business aktif (**termasuk super-admin**, fail-closed; log legacy `NULL` disembunyikan). Backfill otomatis tidak dilakukan; log lama tetap `NULL`.
- **Blocking:** —

### Q13 — Global scope vs scoping eksplisit
- **FAKTA.** Terwujud sebagai **global scope selektif**: trait `App\Models\Concerns\BelongsToBusiness` memasang global scope `business` yang **fail-closed** (flag ON tanpa context terverifikasi → `1 = 0`) + auto-stamp `business_id` di `creating` dari **server context**; master data business-owned memakai trait ini. Model finansial (`Payable`/`Receivable`) memakai global scope khusus (`FinancialOwnershipScope`); sisanya scoping **eksplisit** (mis. `scopeForBusiness`, `OutletAccessService::restrictToActiveBusiness`).
- **KEPUTUSAN (2026-10-13):** **SELESAI** — strategi = eksplisit + global scope **selektif via trait** (bukan global scope serampangan), dengan `withoutGlobalScope` sebagai opt-out terkontrol. Register §13.23.
- **Blocking:** —

### Q14 — Spatie teams vs membership
- **FAKTA.** `config/permission.php` → `'teams' => false`. Per-business role via **membership** (`BusinessUser` + middleware `business.role`/`EnforceBusinessRoleCapability` + `BusinessRoleCapabilities`).
- **KEPUTUSAN (2026-10-13):** **SELESAI** — membership dulu; Spatie teams tidak diaktifkan. Register §13.23.
- **Blocking:** —

### Q15 — `warehouses.outlet_id IS NULL`
- **FAKTA.** `outlet_id` **nullable** (`2026_09_14_000002`) + migrasi mem-backfill `PUSAT` ke outlet. Command read-only: `outlet:audit` (menghitung "warehouses without outlet", `--strict`) dan `outlet:legacy-audit` (klasifikasi record tanpa gudang; `--backfill` hanya memetakan mutasi stok yang unambiguous). `OutletAccessService` menangani `outlet_id` NULL lewat legacy single-outlet bypass terkontrol.
- **KEPUTUSAN (2026-10-13):** **SELESAI** — `outlet_id` NULL = record legacy yang **ditandai lewat audit**, tidak dihapus/dipaksa; `PUSAT` di-backfill ke outlet. Register §13.23.
- **Blocking:** —

### Q16 — Laporan lintas business
- **FAKTA.** Report controller (`app/Http/Controllers/Reports/{Sales,Profit,AdvancedSalesInsights}`) men-scope query lewat `OutletAccessService::warehousesFor()` yang memakai `restrictToActiveBusiness()` + active outlet. **Super-admin pun dibatasi ke business aktif.** Tidak ada agregasi lintas business.
- **KEPUTUSAN (2026-10-13):** **SELESAI** — isolasi per business aktif sudah ditegakkan (selaras "manager hanya business aktif"). **Agregasi lintas business tidak disediakan**; bila owner kelak butuh menggabungkan business miliknya, itu fitur terpisah dengan kontrol eksplisit. Register §13.23.
- **Blocking:** —

---

## 3. Keputusan P0 yang Harus Dijawab Sebelum Implementasi

Ini adalah gate Fase 0. Tanpa jawaban, implementasi berisiko rework besar:

1. **Q7** (existing = satu business?) — menentukan bentuk backfill.
2. **Q4** (isolasi master data) — menentukan skema produk/customer.
3. **Q2** (owner/super-admin) — menentukan model membership & otorisasi.
4. **Q3** (role per business) — menentukan evaluasi permission.
5. **Q1** (definisi completed) — menentukan laporan.
6. **Q8** (batas shared hosting) — menentukan desain job.

**REKOMENDASI.** Jawab keenamnya secara eksplisit dan catat sebagai `Keputusan` di dokumen ini dengan tanggal + penanggung jawab. Setelah itu barulah buat migration (Fase 1).

---

## 4. Status Persetujuan

| Item | Tanggal | Penanggung jawab | Status |
|---|---|---|---|
| **Q6** — pembuatan business | 2026-10-13 | owner | **DISETUJUI — A (admin platform only)**, §13.19 register; self-service owner signup tetap fase lanjut |
| Q1–Q5, Q7–Q16 (sisa) | — | — | **Belum disetujui** (default rekomendasi sementara) |

**FAKTA.** Hanya Q6 yang berstatus disetujui (2026-10-13). Item lain tidak boleh ditandai "approved" tanpa konfirmasi eksplisit pengguna.
