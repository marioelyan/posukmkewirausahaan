# Multi-Business Open Decisions — KasirnyaKWU

- **Status:** keputusan owner tercatat di register (§13: ID stabil `AUTH-*`/`SETTINGS-*`/`FIN-*`/`SCHED-*`/`UNIQUE-*`/`GOV-*`). **Terwujud/selesai:** Q1 (§13.25), Q2/Q3 (§13.14 AUTH), Q4/UNIQUE-10/11 (§13.20–§13.22), Q5 (§13.14 SETTINGS), Q6 (§13.19), Q7/Q9 (§13.13/§13.15/§13.16 FIN + backfill/bootstrap), Q8 (§13.14 SCHED), Q10–Q16 (§13.23/§13.24). **Q1 = A (§13.25) terimplementasi** — `completed` = `payment_status='paid'`, filter diterapkan di dashboard/laporan + test regresi.
- **Basis:** audit `multi-business-tenant-audit.md` §14 + temuan tambahan pada 2026-10-10.
- **Label:** **FAKTA**, **REKOMENDASI**, **ASUMSI**, **PERLU KEPUTUSAN**.
- Dokumen terkait: `multi-business-backend-implementation-plan.md`, `multi-business-data-ownership-matrix.md`, `multi-business-security-test-plan.md`.

> **Aturan.** Keputusan owner dipetakan ke ID stabil di register (§13). Q1–Q16 telah **diputuskan & terwujud** (kecuali butir operasional yang *deferred*: eksekusi runbook produksi, aktivasi flag, self-service owner signup). **Q1 = A (§13.25) SELESAI diimplementasikan** (dashboard/laporan + test). Item tanpa keputusan eksplisit memakai *default rekomendasi* hanya sementara.

---

## 1. Ringkasan Status Keputusan

| ID | Pertanyaan singkat | Rekomendasi default | Menghambat | Status |
|---|---|---|---|---|
| Q1 | Definisi transaksi "completed" | `payment_status='paid'` | Fase 6 | SELESAI (§13.25 — `scopeCompleted()` + filter dashboard/laporan + test) |
| Q2 | Owner global vs membership; super-admin tetap global | Membership `owner`; super-admin global | Fase 1-2 | SELESAI (§13.14 — AUTH-01 hybrid) |
| Q3 | Role berbeda per business | Ya, role di membership | Fase 1 | SELESAI (§13.14 — AUTH-02 role per business) |
| Q4 | Master data terpisah penuh | Ya, terpisah penuh | Fase 4 | SELESAI (§13.14/§13.17 + UNIQUE-10 §13.20/§13.21 + UNIQUE-11 §13.22) |
| Q5 | Setting global vs per business | Hybrid key-based | Fase 1-3 | SELESAI (§13.14 — SETTINGS-01..07 hierarki global→business→outlet) |
| Q6 | Pembuatan business | Admin platform + self-service owner (fase lanjut) | Fase 8 | SELESAI (bagian admin platform; self-service deferred) |
| Q7 | Outlet existing = satu business | Satu business default | Fase 3 | SELESAI (§13.13/§13.16 — FIN-01..05 + bootstrap/backfill) |
| Q8 | Background job di shared hosting | Sinkron + scheduler per menit | Fase 7 | SELESAI (§13.14 — SCHED-01/02 BusinessTaskRunner, tanpa queue) |
| Q9 | Data existing tanpa ownership jelas | Ke business default, ditandai | Fase 3 | SELESAI (§13.13/§13.15 — FIN-04 konteks wajib + karantina/audit) |
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
- **FAKTA.** Tidak ada kolom `status`; hanya `payment_status` (`paid`, `unpaid`, `pending`, `pending_approval`, `failed`). Dashboard & laporan menjumlahkan `grand_total` **semua** baris (`DashboardController:48,54,65,67`; `SalesReportController:56`; `ProfitReportController:54`); shift benar memakai `paid` (`CashierShiftService:106,115`).
- **KEPUTUSAN (A — §13.25, 2026-10-13).** `completed` = `payment_status='paid'`. `unpaid` (piutang) **bukan** omzet selesai; `pending`/`pending_approval`/`failed` **dikecualikan**. Selaras dengan rekap shift.
- **Status implementasi.** **SELESAI (PR #11).** `Transaction::scopeCompleted()` + `Transaction::PAYMENT_STATUS_PAID`. Diterapkan di `DashboardController` (semua agregasi omzet/laba/count; feed aktivitas tidak), `SalesReportController` & `ProfitReportController` (`applyFilters` → daftar + total), `AdvancedSalesInsightsController` (`applyTransactionFilters` + `detailMetricsQuery`), `MemberController` (`member_revenue` + `buildStats`), `CustomerController::buildStats`. Test: `tests/Feature/Reports/CompletedTransactionScopeTest.php`.
- **Sisa (follow-up opsional).** Rekap shift (`CashierShiftService`/`CashierShiftResource`) dan daftar riwayat/activity feed — bukan metrik omzet.
- **Alternatif (ditolak).** A′ `paid`+`unpaid` (akrual); B kolom lifecycle `status` terpisah (migrasi besar, belum ada modul void/refund).
- **Dampak.** Laporan omzet/laba & dashboard berubah (turun) bila ada baris non-`paid`; catat di release notes.
- **Blocking:** — (keputusan & implementasi selesai).

### Q2 — Owner & super-admin
- **KEPUTUSAN (AUTH-01 = C hybrid, register §13.1; terwujud §13.14).** `Owner` adalah role **membership** bisnis, bukan role global; `super-admin` tetap global sebagai operator platform (`User::isSuperAdmin()` bypass). Diimplementasikan via `BusinessRoleCapabilities` + middleware `business.role` (`EnforceBusinessRoleCapability`) + provisioning membership (§13.15).
- **Alternatif (ditolak).** `owner` dijadikan role Spatie global (ambigu lintas business).
- **Dampak.** Model membership, policy, UI.
- **Blocking:** —

### Q3 — Role berbeda per business
- **KEPUTUSAN (AUTH-02 = A, register §13.1; terwujud §13.14).** Boleh berbeda; role disimpan di `business_user`. Penegakan via `business.role` + `BusinessRoleCapabilities` menutup residual permission global akibat `teams=false`.
- **Alternatif (ditolak).** Satu role global per user (tidak fleksibel untuk SaaS).
- **Dampak.** Evaluasi permission per konteks.
- **Blocking:** —

### Q4 — Isolasi master data
- **REKOMENDASI.** Produk/kategori/customer/supplier/unit/price list terpisah penuh per business.
- **Alternatif.** Katalog bersama + override per business (kompleks; menambah kebocoran).
- **Dampak.** POS, impor/ekspor, laporan.
- **Status (2026-10-12):** composite unique per-business SELESAI untuk `products` (barcode/sku), `price_lists` (slug), `warehouses`/`outlets` (code), `customers` (member_code), `customer_vouchers` (code) dan `customer_segments` (slug); `units.code` tetap global (UNIQUE-04).
- **Status (2026-10-13):** UNIQUE-10 (nomor dokumen per business) SELESAI — enam tabel dokumen (PO/GR/SR/ST + `sales_returns.code` + `stock_opnames.code`) mendapat `business_id` + trait + generator per business + swap composite kondisional (register §13.20). Format nomor tetap `PREFIX-YYYYMMDD-####` dengan urutan per business. Sisa UNIQUE-10 juga SELESAI (register §13.21): `payables.document_number` = composite `(business_id, document_number)`, `transactions.invoice` = **global** unique (dibaca tanpa konteks business; per-business justru ambigu), plus validasi `TenantUnique` di `PayableController`. UNIQUE-11 juga ditutup (register §13.22): `client_uuid` **global unique** (sudah ada) + `context_key` global dengan namespacing per business. Detail: register §13.14/§13.17/§13.20/§13.21/§13.22.
- **Blocking:** — (UNIQUE-10 §13.20/§13.21; UNIQUE-11 ditutup global §13.22).

### Q5 — Setting global vs per business
- **KEPUTUSAN (SETTINGS-01..07, register §13.3; terwujud §13.14).** Klasifikasi hybrid hierarki `global → business → outlet` (prioritas outlet > business > global): `PLATFORM_KEYS` (`app_setup_completed`, `setup_warehouse_id`) global; store profile/printer/wa/target per outlet; identitas legal (NPWP/NIB), pajak, loyalty, ambang diskon per business. `settings.business_id` (`2026_10_12_000001`); tulisan tenant tanpa konteks **fail closed** (`TenantSettingScopeException`); command `business:settings-backfill`.
- **Alternatif (ditolak).** Semua setting per business (duplikasi nilai platform).
- **Dampak.** Onboarding, setup wizard, `HandleInertiaRequests`.
- **Blocking:** —

### Q6 — Pembuatan business
- **REKOMENDASI.** Fase awal: hanya admin platform membuat business. Self-service signup owner ditambahkan belakangan dengan kontrol abuse.
- **Alternatif.** Self-service langsung (risiko spam/penyalahgunaan).
- **Dampak.** Route onboarding, `registration.enabled`, `EnsureNotInstalled`.
- **Status (2026-10-12):** bootstrap first-install SELESAI — setup wizard membuat business default + scope saat `multi_business.enabled=true`, admin setup (super-admin) memakai jalur platform (AUTH-01). Yang masih menunggu keputusan: pembuatan business **tambahan** (UI/rollout) dan self-service owner signup. *(dijawab 2026-10-13 — lihat KEPUTUSAN di bawah)*.
- **KEPUTUSAN (2026-10-13):** **A. Admin platform only** — §13.19 register. Super-admin membuat business tambahan via dashboard (`dashboard/businesses*`, middleware `platform.admin`, `step_up`); provisioning via `BusinessProvisioner` (skeleton PUSAT + owner opsional, tanpa `assignUnscoped`); UI `Businesses/{Index,Create}` + item sidebar (tampil saat flag ON + super-admin). Self-service owner signup tetap fase lanjut. UNIQUE-10 (dokumen) dituntaskan §13.20/§13.21; UNIQUE-11 ditutup global §13.22.
- **Blocking:** Fase 8 (sisa: self-service owner signup + aktivasi flag/deployment; bagian admin-platform SELESAI §13.19).

### Q7 — Outlet existing = satu atau banyak business
- **KEPUTUSAN (FIN-01..05, register §13.4; terwujud §13.13/§13.16).** Seluruh instalasi existing dianggap **satu business** (banyak branch) → business default via `business:backfill --create-default`; install baru di-bootstrap wizard (§13.16). Ownership finansial: auto hanya bila 100% terbukti, sisanya manual/karantina.
- **Alternatif (ditolak).** Pemecahan per outlet (butuh pemisahan data manual yang berisiko).
- **Dampak.** Seluruh backfill.
- **Blocking:** — (eksekusi runbook produksi deferred, §13.18)

### Q8 — Background job di shared hosting
- **FAKTA.** Tidak ada `app/Jobs`; scheduler ada di `routes/console.php`; `schedule:run` per menit.
- **KEPUTUSAN (SCHED-01 = C hybrid, SCHED-02; register §13.5; terwujud §13.14).** Tetap sinkron/scheduler tanpa queue worker/Redis/WebSocket. `BusinessTaskRunner` meng-enumerate active business → set context → jalankan → **selalu clear** (`finally`); di-wire ke `reorder:generate`, `crm:sync-segments`, `crm:generate-reminders`.
- **Alternatif (ditolak).** Queue worker (butuh infra tidak tersedia di shared hosting).
- **Dampak.** CRM, reorder, expire transaksi.
- **Blocking:** —

### Q9 — Data existing tanpa ownership jelas
- **KEPUTUSAN (FIN-04 + karantina FIN-03/05, register §13.4; terwujud §13.13/§13.15).** Manual payable/receivable baru **wajib** konteks business (tanpa konteks → tolak); record unresolved **dikarantina** (tersimpan untuk audit, tak terlihat di tenant normal, tak dapat dimutasi). Data existing ditandai lewat `outlet:legacy-audit`/`outlet:audit` + `business:backfill`.
- **Dampak.** Integritas backfill.
- **Blocking:** — (eksekusi produksi deferred, §13.18)

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
| **Q1** — definisi `completed` | 2026-10-13 | owner | **DISETUJUI — A (paid only)**; terimplementasi (PR #11), register §13.25 |
| **Q2, Q3, Q4, Q5, Q7, Q8, Q9** | 2026-10-13 | owner | **DISETUJUI** (rekomendasi diterima), register §13.13–§13.16 |
| **Q6** — pembuatan business | 2026-10-13 | owner | **DISETUJUI — A (admin platform only)**, §13.19 register; self-service owner signup tetap fase lanjut |
| **Q10–Q16** | 2026-10-13 | owner | **DISETUJUI**, register §13.22–§13.23 |
| **FK `restrictOnDelete`** (D4) | 2026-10-13 | owner | **DISETUJUI** — sudah terimplementasi + teruji; §13.26 |
| **Laporan lintas business** | 2026-10-13 | owner | **Per-business dulu** — tanpa laporan konsolidasi; §13.26 |
| **Backfill `audit_logs.business_id` legacy** | 2026-10-13 | owner | **Tidak perlu** (fresh deploy); §13.26 |
| **Aktivasi/deploy produksi** | 2026-10-13 | owner | **Jalur fresh install** (tanpa backfill legacy); §13.26 |

**FAKTA.** Seluruh keputusan P0 dan P1 telah disetujui owner (2026-10-13). Ikuti register §13.13–§13.26 sebagai rujukan tunggal. Item yang tersisa hanya **operasional** (eksekusi deploy) dan **fase lanjut** (self-service owner signup), bukan keputusan terbuka.
