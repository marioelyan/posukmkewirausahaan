# Multi-Business Open Decisions — Point of Sales

- **Status:** PERENCANAAN / DOKUMENTASI ONLY — keputusan yang telah disetujui: **Q6 = A (admin platform only)** via register §13.19 (2026-10-13) dan **Q4/UNIQUE-10 (nomor dokumen per business)** via register §13.20 (2026-10-13). Item lain tetap perencanaan.
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
| Q4 | Master data terpisah penuh | Ya, terpisah penuh | Fase 4 | SELESAI (§13.14/§13.17 + UNIQUE-10 §13.20/§13.21; sisa UNIQUE-11 deferred) |
| Q5 | Setting global vs per business | Hybrid key-based | Fase 1-3 | PERLU KEPUTUSAN |
| Q6 | Pembuatan business | Admin platform + self-service owner (fase lanjut) | Fase 8 | SELESAI (bagian admin platform; self-service deferred) |
| Q7 | Outlet existing = satu business | Satu business default | Fase 3 | PERLU KEPUTUSAN |
| Q8 | Background job di shared hosting | Sinkron + scheduler per menit | Fase 7 | PERLU KEPUTUSAN |
| Q9 | Data existing tanpa ownership jelas | Ke business default, ditandai | Fase 3 | PERLU KEPUTUSAN |
| Q10 | Status modul DineIn | Tentukan lanjut/hapus | Fase 4 | PERLU KEPUTUSAN |
| Q11 | `client_uuid` unik | Per business | Fase 5 | PERLU KEPUTUSAN |
| Q12 | `audit_logs.business_id` | Tambahkan | Fase 2 | PERLU KEPUTUSAN |
| Q13 | Global scope vs eksplisit | Eksplisit dulu, global scope selektif | Fase 2 | PERLU KEPUTUSAN |
| Q14 | Strategi role Spatie teams vs membership | Membership dulu | Fase 1-2 | PERLU KEPUTUSAN |
| Q15 | Penanganan `warehouses.outlet_id IS NULL` | Business default + audit | Fase 3 | PERLU KEPUTUSAN |
| Q16 | Laporan lintas business (owner) | Boleh, hanya business miliknya | Fase 6 | PERLU KEPUTUSAN |

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
- **Status (2026-10-13):** UNIQUE-10 (nomor dokumen per business) SELESAI — enam tabel dokumen (PO/GR/SR/ST + `sales_returns.code` + `stock_opnames.code`) mendapat `business_id` + trait + generator per business + swap composite kondisional (register §13.20). Format nomor tetap `PREFIX-YYYYMMDD-####` dengan urutan per business. Sisa UNIQUE-10 juga SELESAI (register §13.21): `payables.document_number` = composite `(business_id, document_number)`, `transactions.invoice` = **global** unique (dibaca tanpa konteks business; per-business justru ambigu), plus validasi `TenantUnique` di `PayableController`. Tetap deferred: UNIQUE-11 (kandidat menunggu evidence). Detail: register §13.14/§13.17/§13.20/§13.21.
- **Blocking:** — (UNIQUE-10 dituntaskan §13.20 + §13.21; UNIQUE-11 tetap kandidat menunggu evidence + approval).

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
- **KEPUTUSAN (2026-10-13):** **A. Admin platform only** — §13.19 register. Super-admin membuat business tambahan via dashboard (`dashboard/businesses*`, middleware `platform.admin`, `step_up`); provisioning via `BusinessProvisioner` (skeleton PUSAT + owner opsional, tanpa `assignUnscoped`); UI `Businesses/{Index,Create}` + item sidebar (tampil saat flag ON + super-admin). Self-service owner signup tetap fase lanjut. UNIQUE-10 (dokumen) dituntaskan §13.20; UNIQUE-11 tetap deferred.
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
- **FAKTA.** Controller/page DineIn dihapus sebagian di working tree; migration & model masih ada.
- **REKOMENDASI.** Putuskan sebelum Fase 4: lanjutkan (desain ownership) atau hapus (bersihkan migration pasca-deploy).
- **Blocking:** Fase 4.

### Q11 — `client_uuid` unik
- **FAKTA.** Lookup global (`PosApiController.php:743,878`).
- **REKOMENDASI.** Unik per business (constraint gabungan `business_id, client_uuid`).
- **Blocking:** Fase 5.

### Q12 — `audit_logs.business_id`
- **REKOMENDASI.** Tambahkan `business_id` (nullable saat transisi).
- **Blocking:** Fase 2.

### Q13 — Global scope vs scoping eksplisit
- **REKOMENDASI.** Eksplisit dulu; global scope selektif setelah context terbukti selalu ada (termasuk command/API).
- **Blocking:** Fase 2.

### Q14 — Spatie teams vs membership
- **REKOMENDASI.** Membership role dulu; evaluasi teams jika perlu permission custom per business.
- **Blocking:** Fase 1-2.

### Q15 — `warehouses.outlet_id IS NULL`
- **REKOMENDASI.** Business default + ditandai audit; jangan hapus tanpa keputusan.
- **Blocking:** Fase 3.

### Q16 — Laporan lintas business
- **REKOMENDASI.** Owner boleh menggabungkan **hanya** business miliknya; manager hanya business aktif.
- **Blocking:** Fase 6.

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
