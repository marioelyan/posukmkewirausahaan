# Multi-Business — Progress & Handoff (referensi tugas berikutnya)

> **Tujuan dokumen ini:** menjawab *"sudah sampai mana, kekurangannya apa, dan apa langkah berikutnya"* untuk sesi/AI berikutnya. Ditulis 2026-10-13. Semua angka & status di bawah **terverifikasi dari repo**, bukan asumsi.

---

## 0. TL;DR untuk AI berikutnya

- **Semua slice KODE multi-business SELESAI & test hijau.** Objective "staging-ready" tercapai.
- **Feature flag tetap `false`** (`MULTI_BUSINESS_ENABLED=false` di `config` default & `.env.example`). `.env` lokal `true` (untracked).
- `development` = `92bf141` (Merge PR #9), `main` = `d8a3fdd` (**belum tersentuh**).
- **Yang tersisa:** (a) **eksekusi operasional** (runbook backfill produksi → aktivasi flag → deploy), (b) **fitur fase lanjut** (self-service owner signup), (c) **cleanup opsional** (Q10 sisa migrasi DineIn). **Implementasi kode Q1 sudah SELESAI (PR #11)** — lihat §4.1.
- **Jangan sentuh `main`** — `main` = jalur rilis. Kerja selalu lewat PR ke `development`. (Deploy produksi **manual** via panel DewaBiz — tidak ada auto-deploy; lihat `docs/production-deployment.md`.)
- **Jangan aktifkan flag di produksi** tanpa approval owner + DBA + runbook (`multi-business-backfill-production-runbook.md`).
- Baca berurutan untuk konteks penuh: dokumen ini → `multi-business-open-decisions.md` → `multi-business-blocker-review-and-decision-register.md` (§13 Decision Log) → `multi-business-phase-4f-implementation-notes.md` → `multi-business-backfill-production-runbook.md`.

---

## 1. Snapshot status (per 2026-10-13)

| Item | Nilai |
|---|---|
| Branch aktif | `development` (sinkron `origin/development`) |
| `development` | `92bf141` (Merge PR #9) |
| `main` | `d8a3fdd` (tak tersentuh / belum ada deploy baru dari kerja ini) |
| Remote | `github.com/marioelyan/posukmkewirausahaan` (fork) |
| Feature flag | `MULTI_BUSINESS_ENABLED=false` |
| Test suite | **747 passed (3161 assertions)** (lokal, SQLite in-memory) |
| CI | PR terakhir PASS (~2m16s); job deploy = *skipping* (hanya `main`) |
| Lint | `vendor/bin/pint` bersih |
| Mutasi data produksi | **Nol** |

### Riwayat PR (semua ter-merge)
| PR | Isi |
|---|---|
| #1 | tenancy staging-ready (SETTINGS, UNIQUE, SCHED, AUTH, memberships, onboarding, UNIQUE-08/09, runbook) |
| #2 | Q6-A admin-only business creation (UI + provisioning) |
| #3 | audit staging-ready — sinkron status keputusan |
| #4 | UNIQUE-10 per-business document numbers |
| #5 | UNIQUE-10 residual (`payables.document_number` + `transactions.invoice`) |
| #6 | UNIQUE-11 global idempotency token (docs-only) |
| #7 | pass status Q10–Q16 (berbasis bukti) |
| #8 | Q12 `audit_logs.business_id` ownership + viewer scoping |
| #9 | sync status keputusan Q1–Q9 + dokumen progress/handoff (docs-only) |

---

## 2. Yang SUDAH selesai (dengan bukti)

### 2.1 Skema & tenancy
- `businesses` + `business_user` (membership) + `business_id` pada outlet, master data, settings, payables/receivables, CRM blocks, dokumen (PO/GR/SR/ST, sales_returns, stock_opnames), `audit_logs`. (Migrasi §3.)
- **Middleware** (`routes/web.php` grup dashboard): `business` → `business.required` → `business.role` (`ResolveBusinessContext`, `EnsureActiveBusiness`, `EnforceBusinessRoleCapability`). Tanpa konteks saat flag ON → **403 (fail-closed)**.
- **Konteks**: `App\Services\BusinessContext` (`setActive`, `businessId`, `hasActiveBusiness`), switch via `Route::post('/dashboard/business', ...)` dan `/dashboard/outlet`.

### 2.2 Keputusan owner — semua sudah diputuskan & terwujud (Q1 terimplementasi)
| ID | Keputusan | Terwujud | Ringkas |
|---|---|---|---|
| AUTH-01/02/03 (Q2/Q3/Q14) | Hybrid membership + Spatie capability; role per business; `teams=false` | §13.14 | `BusinessRoleCapabilities` + `business.role`; owner/manager/staff |
| UNIQUE-01..07 (Q4) | Per-business composite unique | §13.14 | identifier milik business |
| UNIQUE-08/09 + CRM | Ownership `customer_campaigns`/`customer_segments` | §13.17 | global scope selektif fail-closed |
| UNIQUE-10 (Q4) | Nomor dokumen per business | §13.20/§13.21 | PO/GR/SR/ST + returns/opname; `payables.document_number` composite; `transactions.invoice` **global** |
| UNIQUE-11 (Q11) | Idempotency token **global** (docs-only) | §13.22 | `transactions.client_uuid` global; namespacing `customer_campaigns.context_key` |
| SETTINGS-01..07 (Q5) | Hierarki `outlet → business → global`; `PLATFORM_KEYS` global; tulis tenant tanpa konteks fail-closed | §13.14 | `settings.business_id`; `TenantSettingScopeException` |
| SCHED-01/02 (Q8) | Sinkron/scheduler, tanpa queue | §13.14 | `BusinessTaskRunner` enumerate active business → set/clear context |
| FIN-01..05 (Q7/Q9/Q15) | Ownership finansial: 6 status + karantina + FIN-04 konteks wajib | §13.13 | `FinancialOwnershipService`; scope karantina |
| Q6 | Admin platform membuat business (+ UI) | §13.19 | `BusinessAdminController`; self-service **deferred** |
| Q7 | Instalasi existing = satu business default | §13.16 | bootstrap first-install + `business:backfill --create-default` |
| Q10 | Modul DineIn dihapus | §13.23 | sisa 1 migrasi drop shim |
| Q12 | `audit_logs.business_id` + scoping viewer | §13.24 | stamp dari `BusinessContext`; legacy NULL disembunyikan |
| Q13 | Global scope selektif fail-closed (trait `BelongsToBusiness`) | §13.23 | eksplisit + fail-closed |
| Q15 | `warehouses.outlet_id` nullable + backfill PUSAT + audit read-only | §13.23 | |
| Q16 | Laporan scope per business aktif (termasuk super-admin), **tanpa agregasi lintas business** | §13.23 | `OutletAccessService::warehousesFor` + `restrictToActiveBusiness` |

### 2.3 Onboarding & demo
- **First-install wizard** `/setup` (`setup.notinstalled`): buat admin, profil toko, business default (saat flag ON), kategori, gudang utama. `Setting::app_setup_completed` mengunci.
- **Demo seeder** (`seed:demo` / `DemoSeeder --force`, **dilarang di produksi**): outlet `MAL`/`TKB`/`PUT`; akun `arya@gmail.com` (super-admin), `manager@gmail.com`, `cashier@gmail.com` (password `password`); membership demo (manager→`manager`, cashier→`staff`).
- **Commands backfill** (dry-run default, aman tanpa flag): `business:backfill`, `business:settings-backfill`, `business:membership-backfill`, `business:financial-ownership-backfill|assign|report`, `outlet:audit`, `outlet:legacy-audit`, `inventory:reconcile`.

### 2.4 Dokumentasi tuntas
Decision log `§13.1–§13.24`, open-decisions Q1–Q16, audit, ownership matrix, security test plan, 8 catatan fase, runbook produksi (DRAFT), phase-4f notes. Tabel status Q1–Q9 sudah disinkronkan (PR #9).

---

## 3. Migrasi era multi-business (14 file, semua aditif/guarded)

```
2026_10_10_150216_create_businesses_table
2026_10_10_150300_add_business_id_to_outlets_table
2026_10_10_150736_create_business_user_table
2026_10_10_160000_add_business_id_to_master_data_tables
2026_10_11_000001_drop_dine_in_tables                 # Q10 — shim drop (no-op fresh install)
2026_10_11_100001_add_business_id_to_payables_table    # FK RESTRICT
2026_10_11_100002_add_business_id_to_receivables_table # FK RESTRICT
2026_10_11_100003_add_ownership_status_to_financial_tables
2026_10_12_000001_add_business_id_to_settings_table
2026_10_12_000002_add_business_scoped_unique_indexes
2026_10_12_000003_add_business_scope_to_crm_blocks
2026_10_13_000001_scope_document_numbers_to_business
2026_10_13_000002_add_unique_payable_document_number_and_transaction_invoice
2026_10_13_000003_add_business_id_to_audit_logs_table
```

---

## 4. KEKURANGAN / yang BELUM selesai

### 4.1 Keputusan bisnis
- **Q1 — Definisi transaksi "completed": DIPUTUSKAN = A (§13.25) dan SUDAH DIIMPLEMENTASIKAN (PR #11).** `completed` = `payment_status='paid'`; `unpaid` (piutang) bukan omzet selesai; `pending`/`pending_approval`/`failed` dikecualikan.
  - **Implementasi (selesai):**
    - `app/Models/Transaction.php` — `scopeCompleted()` + konstanta `PAYMENT_STATUS_PAID` (satu definisi bersama).
    - `app/Http/Controllers/DashboardController.php` — `totalTransactions/totalRevenue/totalProfit/averageOrder/todayTransactions/todaySales/todayProfit/currentMonthSales/revenueTrend/topProducts/topCustomers/topLocations/recentlySoldProductIds`. Feed `recentTransactions` & `setupChecklist` sengaja **tidak** difilter (bukan omzet).
    - `app/Http/Controllers/Reports/SalesReportController.php` & `ProfitReportController.php` — `applyFilters()` (daftar **dan** total konsisten).
    - `app/Http/Controllers/Reports/AdvancedSalesInsightsController.php` — `applyTransactionFilters()` + `detailMetricsQuery()`.
    - `app/Http/Controllers/Apps/MemberController.php` (`member_revenue` + `buildStats`) & `CustomerController::buildStats`.
    - Sudah benar sebelumnya: `app/Services/CashierShiftService.php` (kas, baris 106/115).
  - **Test:** `tests/Feature/Reports/CompletedTransactionScopeTest.php` (7 test) — non-`paid` tidak masuk omzet dashboard/sales/profit/insights/member; `paid` masuk; feed aktivitas tetap menampilkan semua.
  - **Sisa (follow-up opsional):** rekap shift (`CashierShiftService`/`CashierShiftResource`) & daftar riwayat — bukan metrik omzet.
  - **Catatan:** angka historis dashboard/laporan berubah (**turun**) bila ada baris non-`paid`; cantumkan di release notes.
  - **Tidak memblokir** staging/aktivasi teknis.

### 4.2 Operasional (deferred — butuh approval, bukan kode)
- **Aktivasi flag `MULTI_BUSINESS_ENABLED=true`** — **Jalur A (fresh install):** tidak perlu backfill. Ikuti `multi-business-backfill-production-runbook.md` §0 dan §13.26 (deploy → `migrate` → `/setup wizard`).
- **Deployment** ke produksi — **manual**: merge rilis ke `main` → tag `vX.Y.Z` (+ `APP_VERSION`) → `./scripts/deploy-build.sh` → upload/extract via panel DewaBiz → artisan via cron. Tidak ada auto-deploy. Gunakan jalur fresh-install.
- **Self-service owner signup** (Q6 fase lanjut) — bagian admin-platform sudah selesai; signup mandiri belum.
- **Eksekusi runbook backfill produksi** — **Jalur B (existing):** DRAFT (referensi); berdasarkan keputusan owner (2026-10-13) **tidak berlaku** (§13.26).
- **Self-service owner signup** (Q6 fase lanjut) — bagian admin-platform sudah selesai; signup mandiri belum.

### 4.3 Cleanup opsional
- **Q10 residu**: `database/migrations/2026_10_11_000001_drop_dine_in_tables.php`. Idempotent/no-op pada install fresh; disarankan dihapus **pasca-deploy** (jangan hapus sebelum jendela deploy bila masih membersihkan install lama).

### 4.4 Residual teknis / risiko (perlu diketahui sebelum deploy)
- **FK `restrictOnDelete`** pada `payables`/`receivables.business_id` — **DISETUJUI** (§13.26); sudah terimplementasi + teruji.
- **`teams=false` residual**: role ditegakkan via membership + `business.role`.
- **`transactions.invoice` global unique** & **`transactions.client_uuid` global unique** — disengaja (idempotency lintas business).
- **`units.code` global** (satu-satunya identifier master data yang tetap global).
- **`audit_logs.business_id` legacy = NULL** dan **disembunyikan** saat flag ON (fail-closed, tanpa backfill otomatis) — disetujui **tidak perlu** (§13.26).
- **Laporan lintas business** — **per-business dulu** (§13.26); tidak ada konsolidasi rilis ini.
- **Data existing tanpa ownership jelas** → dikarantina (FIN-05) — **hanya Jalur B**; tidak berlaku fresh-install.
- **Permission cache stale** setelah seeder → logout + login.

---

## 5. Pra-syarat sebelum fase berikutnya (checklist ringkas)

**Jalur A (fresh install) — siap go-live:**
- [x] Keputusan owner lengkap (**§13.26**).
- [x] Kode + test hijau (754 passed, 3253 assertions); Q1 terimplementasi (PR #11).
- [ ] Siapkan release `release/X.Y.Z` dari `development`; periksa `APP_VERSION`; tag; go-live = merge ke `main` lalu **deploy manual** (package → panel → cron).
- [ ] Go-live: `MULTI_BUSINESS_ENABLED=true` → `php artisan migrate --force` → `php artisan storage:link` → root → `/setup wizard` → smoke test `docs/testing-manual.md`.

**Jalur B (existing) — TIDAK DIJALANKAN saat ini (draft hanya referensi).**

### Untuk mengaktifkan flag di staging (QA)
```text
[ ] php artisan migrate                       # schema multi-business
[ ] business:backfill --create-default         # dry-run dulu, lalu --apply --confirm-legacy-ownership
[ ] business:settings-backfill --apply         # (dry-run default: jalankan tanpa --apply untuk plan)
[ ] business:membership-backfill --apply       # (dry-run default)
[ ] business:financial-ownership-report --format=json --list   # baseline read-only
[ ] business:financial-ownership-backfill (dry-run) → --apply --confirm-financial-ownership
[ ] business:financial-ownership-assign ...    # untuk manual_required/ambiguous/dangling
[ ] set MULTI_BUSINESS_ENABLED=true (staging) → smoke test (docs/testing-manual.md)
```
> Semua command backfill **bekerja tanpa flag ON** (bekerja terhadap kolom), jadi aman dijalankan bertahap.

### Untuk produksi
```text
[ ] read replica / restored copy + backup terverifikasi
[ ] approval owner + DBA (audit, apply, swap index)
[ ] jendela perawatan + maintenance:down saat Fase 4
[ ] rehearsal wajib pada restored copy (Fase 4–6)
[ ] swap index manual (DBA) → verifikasi SHOW INDEX
[ ] Fase 7 aktivasi = keputusan terpisah
```

---

## 6. Peta file kunci

### Kode inti
| Area | File |
|---|---|
| Konteks bisnis | `app/Services/BusinessContext.php`, `BusinessHierarchyService.php`, `BusinessMembershipResolver.php` |
| Akses outlet | `app/Services/OutletAccessService.php` (`warehousesFor`, `restrictToActiveBusiness`) |
| Audit | `app/Services/AuditLogService.php`, `app/Models/AuditLog.php`, `app/Http/Controllers/Apps/AuditLogController.php` |
| Finansial | `app/Services/FinancialOwnershipService.php`, `app/Support/Tenancy/FinancialOwnershipScope.php`, `TenantOwnership.php` |
| Settings | `App\Services\...` + `TenantSettingScopeException`, `PLATFORM_KEYS` |
| Scheduler | `BusinessTaskRunner` (enumerate active business) |
| Middleware | `app/Http/Middleware/{ResolveBusinessContext,EnsureActiveBusiness,EnforceBusinessRoleCapability}.php` |
| Admin business | `app/Http/Controllers/Apps/BusinessAdminController.php`, `BusinessContextController.php` |
| Trait scope | `BelongsToBusiness` (global scope selektif fail-closed) |

### Konfigurasi & rute
- `config/multi_business.php` (`enabled`, `default_name`).
- `routes/web.php`: grup dashboard `['auth','business','business.required','business.role']`; bisnis admin di luar `business.required` (super-admin only).
- `routes/api.php`: `/api/v1` (Sanctum abilities `{module}-*`, `pos-access`).

### Test (30 file di `tests/Feature/Business/`)
Termasuk: `AuditLogBusinessScopeTest`, `ReportIsolationTest`, `MasterDataIsolationTest`, `SettingTenantScopeTest`, `DocumentNumberTenancyTest`, `FinancialOwnership*Test`, `SchedulerTenantContextTest`, `AuthzBusinessRoleEnforcementTest`, `TenantRouteBindingTest`, dll.

### Dokumentasi
- `docs/architecture/multi-business-open-decisions.md` — Q1–Q16 + status.
- `docs/architecture/multi-business-blocker-review-and-decision-register.md` — risalah + **§13 Decision Log**.
- `docs/architecture/multi-business-phase-4f-implementation-notes.md` — catatan FIN + daftar deferred.
- `docs/architecture/multi-business-backfill-production-runbook.md` — runbook produksi (DRAFT).
- `docs/architecture/multi-business-data-ownership-matrix.md`, `multi-business-security-test-plan.md`, `multi-business-tenant-audit.md`, `multi-business-readonly-audit-queries.md`.
- `AGENTS.md` (root) — konvensi repo, arsitektur, gotcha.

---

## 7. Command cheat-sheet

```bash
# Dev
composer run dev

# Test
php artisan test
php artisan test --filter=AuditLogBusinessScopeTest

# Backfill (dry-run default — aman tanpa flag)
php artisan business:backfill
php artisan business:backfill --create-default --apply --confirm-legacy-ownership
php artisan business:settings-backfill
php artisan business:membership-backfill
php artisan business:financial-ownership-report --format=json --list
php artisan business:financial-ownership-backfill           # dry-run
php artisan business:financial-ownership-backfill --apply --confirm-financial-ownership
php artisan business:financial-ownership-assign {table} {id} {business} --reason="..."
php artisan outlet:audit
php artisan outlet:legacy-audit
php artisan inventory:reconcile [--fix]

# Demo (JANGAN di produksi)
php artisan seed:demo --force

# Formatting
vendor/bin/pint
```

---

## 8. Gotcha lingkungan (dari pengalaman sesi ini)

- **Tidak ada auto-deploy.** `main` = jalur rilis; deploy produksi manual via panel DewaBiz (package + cron). Selalu PR ke `development`.
- **`gh pr edit` gagal** (GraphQL "Projects (classic) is being deprecated", exit 1). Workaround: `gh api -X PATCH repos/marioelyan/posukmkewirausahaan/pulls/N --input <file.json>` — bangun JSON via PHP (`jq` **tidak** terpasang).
- **`gh pr create`**: selalu pakai `--body-file` (backtick di body kena shell command substitution).
- **Flags test**: `phpunit.xml` mem-*pin* `MULTI_BUSINESS_ENABLED=false`; test tenancy menyalakannya eksplisit + `withSession(['active_business_id' => $id])`.
- **Test conventions**: `RefreshDatabase`; seed `PermissionSeeder→RoleSeeder→UserSeeder`; `markEmailAsVerified()` sebelum `actingAs`; `Product` wajib `tax_rate=0`; `PUSAT` = gudang `type='main'`.
- **Demo seeder dilarang di produksi**; **email verification disabled**.
- **Branch lokal bisa basi**: setelah merge PR, `git fetch --prune` lalu cek `git ls-remote --heads origin` untuk memastikan ref remote (tracking ref bisa tertinggal).
- **PERSISTENSI COMMIT/MERGE (PENTING):** environment ini pernah membuat commit/merge **tampak sukses di lokal** (bahkan `gh pr create/merge` mengembalikan URL & `git log` lokal berubah) **tetapi tidak sampai ke remote**. Terjadi dua kali. **Selalu verifikasi ke remote**, jangan percaya state lokal saja:
  - `git ls-remote --heads origin` — pastikan commit ada di `refs/heads/<branch>`.
  - `gh pr view <N> --json state` — pastikan benar-benar `MERGED`.
  - Setelah merge: `git fetch origin --prune && git checkout -B development origin/development` untuk memaksa sinkron.
  - Kerjakan checkout/commit/push dalam **satu perintah atomik**; `gh` sesekali balas `401` (flaky) — ulangi.
  - File body PR (`--body-file`) kadang hilang dari `/tmp/**`; tulis ulang bila `gh` bilang "no such file".

---

## 9. Saran task berikutnya (prioritas)

## 9. Saran task berikutnya (prioritas)

1. ~~**[Kode] Implementasi Q1** — filter `payment_status='paid'` + test regresi.~~ **SELESAI (PR #11)** — lihat §4.1.
2. ~~**[Ops] Persetujuan runbook** — review & approval owner + DBA; siapkan akses read replica + jadwal jendela perawatan.~~ **RESOLVED** — owner setujui jalur **fresh install** (tanpa backfill), §13.26.
3. ~~**[Ops] Rehearsal + eksekusi runbook** pada restored copy, lalu Fase 4–6 live.~~ **TIDAK DIPERLUKAN** (fresh install — cukup deploy + setup wizard).
4. **[Ops] Go-live fresh install** — deploy kode (MAIN, via release), set `MULTI_BUSINESS_ENABLED=true`, `migrate`, `storage:link`, buka root → **/setup wizard** (bootstrap default business Q7), smoke test (`docs/testing-manual.md`), logout+login.
5. **[Fitur, opsional] Self-service owner signup** (Q6 fase lanjut) — desain kontrol abuse.
6. **[Cleanup, opsional] Hapus sisa migrasi drop DineIn** setelah deploy (Q10).
7. **[Fitur, opsional] Laporan konsolidasi lintas business** bila owner butuh (saat ini by-design tidak ada; keputusan 2026-10-13: per-business dulu).
8. **[Fitur, opsional] Command backfill `audit_logs.business_id` legacy** bila histori perlu terlihat setelah flag ON (2026-10-13: tidak perlu).

---

## 10. Pertanyaan terbuka untuk owner (bila AI melanjutkan)

1. ~~**Q1**: definisi transaksi "completed"~~ — **DIPUTUSKAN = A & SUDAH DIIMPLEMENTASIKAN** (§13.25, PR #11).
2. ~~Apakah **FK `restrictOnDelete`** (bisnis diarsipkan, tak bisa hard-delete) disetujui? (§13.13 D4)~~ — **DISETUJUI** (§13.26).
3. ~~Apakah owner butuh **laporan konsolidasi lintas business** (fitur baru) atau cukup per-business?~~ — **Per-business dulu** (§13.26).
4. ~~Apakah **histori `audit_logs` legacy** perlu di-backfill agar terlihat setelah flag ON?~~ — **Tidak perlu** (§13.26).
5. ~~Jadwal **jendela perawatan** & kesiapan **read replica/DBA** untuk eksekusi runbook.~~ — **Tidak diperlukan** (fresh install, §13.26).

---

*Dokumen ini adalah referensi kerja; keputusan resmi tetap di `multi-business-blocker-review-and-decision-register.md` §13.*
