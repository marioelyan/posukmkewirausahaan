# Multi-Business — Blocker Review & Decision Register

- **Jenis dokumen:** AUDIT READ-ONLY. Tidak ada implementasi.
- **Tanggal:** 2026-10-11
- **Branch / commit:** `main` @ `d8a3fdd` (working tree berisi pekerjaan fase sebelumnya, tidak diubah oleh audit ini).
- **Feature flag:** `multi_business.enabled` = `false` (default; `MULTI_BUSINESS_ENABLED` tidak ada di `.env`/`.env.example`).
- **Cakupan:** Q2/Q3/Q14 (membership & authorization), Q7/Q9/Q15 (legacy ownership & financial records), Q4 (tenant-scoped uniqueness), Q5 (settings & store profile).
- **Aturan label:** **FAKTA** (dibuktikan kode/schema/test/ADR), **KESIMPULAN TEKNIS** (interpretasi berbukti), **ASUMSI** (sementara, bukan keputusan), **PERLU KEPUTUSAN** (bisnis, belum disetujui).

> **Penting — ketidaksesuaian penomoran Q.** Sebagian ID di task ini berbeda dari `docs/architecture/multi-business-open-decisions.md`:
> - Task: **Q4** = tenant-scoped **uniqueness**; **Q7/Q9/Q15** = **legacy ownership payable/receivable & orphan**; **Q5** = settings.
> - `multi-business-open-decisions.md`: **Q4** = "Master data terpisah penuh"; **Q7** = "Outlet existing = satu business"; **Q9** = "Data existing tanpa ownership jelas"; **Q15** = "`warehouses.outlet_id IS NULL`".
> - ADR fase sebelumnya (`ADR-02`, `ADR-03`, `ADR-04`) memakai penomoran versi task, bukan versi open-decisions.
>
> **KESIMPULAN TEKNIS:** ada dua skema penomoran yang hidup bersamaan (open-decisions Q1–Q16 vs task/ADR Q2/Q3/Q4/Q5/Q7/Q9/Q14/Q15). Ini harus direkonsiliasi sebelum keputusan ditandatangani, agar tidak salah merujuk. Register di bawah memakai penomoran task, dengan pemetaan ke open-decisions dicatat.

---

## 1. Executive summary

**FAKTA.** Fondasi multi-business sudah ada di working tree (uncommitted): model membership `BusinessUser`, resolver tenant (`ResolveBusinessContext`, `EnsureActiveBusiness`, `BusinessMembershipResolver`, `BusinessContext`), resolver ownership (`TenantOwnership`), rule FK (`BelongsToActiveBusiness`), uniqueness aplikasi (`TenantUnique`), command backfill dry-run (`business:backfill`), dan suite test isolasi (`tests/Feature/Business/*`, `tests/Feature/Tenancy/*`). Semua guard inert saat flag off.

**FAKTA.** **Tidak ada satu pun keputusan bisnis** di keempat kelompok blocker yang berstatus disetujui. `multi-business-open-decisions.md` §4 menegaskan "Semua Q1-Q16 — Belum disetujui".

> **UPDATE 2026-10-11 — lihat §13.** Pemilik proyek telah memberikan keputusan eksplisit atas decision gate. Keputusan dicatat di **§13 (Decision Log)** dengan status **`APPROVED — DOCUMENTATION ONLY`** dan ID stabil (`AUTH-*`, `UNIQUE-*`, `SETTINGS-*`, `FIN-*`, `SCHED-*`, `GOV-*`). Ini **bukan** izin implementasi: tidak ada migration, backfill, schema/data change, aktivasi feature flag, atau deployment.

**KESIMPULAN TEKNIS — kesiapan per kelompok:**

| Kelompok | Kondisi implementasi | Yang menahan |
|---|---|---|
| Q2/Q3/Q14 | Membership model + resolver siap; **role membership tidak dipakai untuk otorisasi** (permission Spatie global, `teams=false`) | Keputusan apakah role per-business mengekang permission (Q3) dan model Q14 |
| Q7/Q9/Q15 | Ownership via parent (`purchase_order_id`/`transaction_id`) sudah fail-closed; record tanpa parent **tidak dapat dibuktikan ownership**; ada fallback baca `Outlet::active()->count() <= 1` | Kebijakan ownership legacy + kebijakan manual payable/receivable + definisi orphan |
| Q4 | `categories.name` & `customers.no_telp` sudah tenant-scoped di aplikasi; mayoritas field lain **unique global** di DB | Persetujuan scope per field sebelum rancangan composite index |
| Q5 | Settings punya `(key, outlet_id)`; **tidak ada `business_id`**; sebagian key ditulis global | Klasifikasi global/per-business/per-outlet (Q5) |

**KESIMPULAN TEKNIS — kelayakan implementasi:** **belum siap untuk implementasi apa pun** tanpa keputusan eksplisit. Namun ada pekerjaan **read-only** (audit duplikasi, inventaris orphan, snapshot settings, matriks membership) yang dapat berjalan paralel tanpa menunggu keputusan.

**Status akhir:** `Complete with approval blockers` — audit selesai; tidak ada keputusan yang dibuat atas nama pemilik produk. **Diperbarui 2026-10-11:** keputusan owner tercatat sebagai **`APPROVED — DOCUMENTATION ONLY`** di §13 (belum izin implementasi).

---

## 2. Repository evidence

Semua referensi di bawah diverifikasi langsung dari file aktual (baris disebutkan).

### 2.1 Feature flag & tenancy primitives
- `config/multi_business.php:16` — `'enabled' => (bool) env('MULTI_BUSINESS_ENABLED', false)`; komentar eksplisit "Keep this false in production until the backfill is applied".
- `app/Http/Middleware/ResolveBusinessContext.php:31-58` — super-admin boleh business `active` mana pun (`:32-40`); non-super via resolver (`:41-47`); auto-pilih hanya bila tepat satu membership (`:52-58`).
- `app/Http/Middleware/EnsureActiveBusiness.php:18` no-op saat flag off; `:22-23` `abort(403)` tanpa context saat flag on (fail closed).
- `app/Services/BusinessMembershipResolver.php:12-29` menolak membership/business non-`active`; `:45-50` `soleActiveMembershipFor` hanya bila count === 1.
- `app/Services/BusinessContext.php:48-51` `role()`; `:53-57` `isActiveMembership()`.
- `app/Providers/AppServiceProvider.php:22` `$app->scoped(BusinessContext::class)`; `:41-42` clear pada `RequestHandled` (tidak bocor antar request).

### 2.2 Membership / role / permission
- `app/Models/BusinessUser.php` — konstanta `ROLE_OWNER|MANAGER|STAFF`, `STATUS_INVITED|ACTIVE|SUSPENDED|REVOKED`; tabel `business_user`.
- `database/migrations/2026_10_10_150736_create_business_user_table.php:23` — unique `(business_id, user_id)`; `:24-25` index `(user_id,status)` & `(business_id,status)`.
- `app/Models/Business.php:24` `owner_user_id` fillable; `:35-38` relasi `owner()`; `:57-59` `scopeActive`.
- `app/Models/User.php:100` `isSuperAdmin() = hasRole('super-admin')`; `:105-113` `hasPermissionTo` super-admin bypass; `:133-147` `memberships()`/`businesses()`/`membershipsInActiveState()`.
- `config/permission.php:126` — `'teams' => false` (tidak ada team/business scoping).
- `app/Providers/AppServiceProvider.php:37` — satu-satunya `Gate::define` (`viewApiDocs`). **Tidak ada Policy/Gate yang membaca `BusinessContext::role()`** (grep kosong).

### 2.3 Ownership / financial records
- `app/Support/Tenancy/TenantOwnership.php:73-76` — `VIA_PARENT_TABLES = ['payables' => ['purchase_orders','purchase_order_id'], 'receivables' => ['transactions','transaction_id']]`.
- `TenantOwnership.php:99-147` — `entityBelongsToActiveBusiness`; `:111-113` id null → false; `:139-142` parent null / dangling → false (fail closed); `:145-146` table tak dikenal → true (tidak ditegakkan).
- `app/Http/Controllers/Apps/PayableController.php:222-233` — `scopeQuery`; `:229` `elseif (Outlet::active()->count() <= 1) { $builder->orWhereDoesntHave('purchaseOrder'); }`.
- `PayableController.php:235-240` — `ensureAccess` → `canUseWarehouse(user, $payable->purchaseOrder?->warehouse)`.
- `PayableController.php:71-89` — `store` manual (`Payable::create($data)` tanpa `purchase_order_id`).
- `app/Services/ReceivableService.php:112-131` — `scope` + `singleOutlet()` (`:124` fallback manual, `:131` `Outlet::active()->count() <= 1`).
- `app/Services/PayableAgingService.php:34-51` scopeQuery (`:48-50` fallback); `:53` `getTopSuppliersByPayable`; `:75` `getDueSoonPayables`; `:93-95` `singleOutlet()`.
- `app/Services/GoodsReceivingService.php:166-201` — `createOrUpdatePayable` (`updateOrCreate(['purchase_order_id' => $order->id], …)`) → owned.
- `app/Services/CheckoutService.php:134-143` — `Receivable::create([... 'transaction_id' => $transaction->id ...])` → owned.
- `app/Services/OutletAccessService.php:16-48` — `canUseWarehouse`/`canSellAtWarehouse`; `:232-236` `legacySingleOutletBypass()` = `config('security.outlet.legacy_single_outlet_bypass', true) && Outlet::active()->count() <= 1`.
- `config/security.php:28` — `legacy_single_outlet_bypass` default `true`.
- `Outlet::active()->count() <= 1` juga dipakai di: `PayableController:229`, `ReceivableService:131`, `PayableAgingService:95`, `OutletAccessService:235`, `DashboardController:33`, `CrmReminderController:26`, `AdvancedSalesInsightsController:61`.

### 2.4 Uniqueness
- DB unique (terverifikasi, contoh): `products.barcode` (`2024_06_13_131744:20`), `products.sku` (`2025_12_28_105534:15`), `price_lists.slug` (`2026_06_22_000015:14`), `units.code` (`2026_06_22_000010:14`), `warehouses.code` (`2026_06_22_000001:13`), `outlets.code` (`2026_09_14_000001:13`), `customers.member_code` (`2026_05_05_000102:13`), `customer_vouchers.code` (`2026_05_05_000104:14`), `customer_segments.slug` (`2026_05_06_000110:14`), `settings(key,outlet_id)` (`2026_09_14_000004:17-18`).
- `app/Support/Tenancy/TenantUnique.php:24-49` — rule tenant-scoped; `:36` parity saat flag off; `:42-45` fail closed tanpa context.
- Pemakai `TenantUnique`: `Apps/CustomerController:83,127,228`, `Apps/MemberController:211`, `Api/CustomerController:50,94`, `Api/CategoryController:37,65`.
- `app/Http/Controllers/Apps/CategoryController.php:52-56` — web validasi `name` hanya `required` (tanpa unique).
- `app/Http/Controllers/Apps/CustomerSegmentController.php:205` — cek slug unik **global** (tanpa ownership).
- `app/Imports/ProductsImport.php:38-45`, `app/Imports/CustomersImport.php:30-40` — ownership diisi dari context server (bukan file), fail closed tanpa context.

### 2.5 Settings
- `database/migrations/2025_12_25_200000_create_settings_table.php:16` `key` unique; `2026_09_14_000004_add_outlet_id_to_settings_table.php:17-18` drop unique `key`, tambah unique `(key, outlet_id)`. **Tidak ada `business_id`.**
- `app/Models/Setting.php:23-33` `get` (global, `outlet_id` null); `:45-59` `getForOutlet` (outlet → fallback global); `:66-80` `setForOutlet` (fallback `set` bila outlet null); `:85-96` `set` (global); `:98-107` `setMany` (loop `set`, global).
- Penulis global (lintas business): `SettingController:129-131` (`store_npwp`, `store_nib`, `tax_default_rate`), `LoyaltyService:92` (`setMany`), `SetupController:130,140,182-189`.
- Pembaca global: `Api/AuthController.php:122-124` (store profile), `Models/Transaction.php:170-171` (`discount_approval_*`), `LoyaltyService:30-55` (`loyalty_*`), `DashboardController:243` (`app_setup_completed`), `BusinessBackfillCommand:74`.
- Pembaca per-outlet: `HandleInertiaRequests:211-228`, `SettingController:65-74,102-111,161-162,244-257`, `DocumentController:38-64`, `ThermalPrintService:15-17,114-115`, `WhatsAppService:13,42`, `CrmAutomationService:147-148`, `TaxService:12` (fallback global).

### 2.6 Scheduler / jobs
- `app/Jobs/` **tidak ada** (grep/ls kosong).
- `routes/console.php:11-14` — `crm:sync-segments` (01:00), `crm:generate-reminders` (01:15), `reorder:generate` (02:00), `transactions:expire` (hourly). Tidak menetapkan business context.
- `app/Console/Commands/BusinessBackfillCommand.php:14-19` — default **dry-run**; butuh `--apply` + `--confirm-legacy-ownership` (`:117-120`).

### 2.7 Test isolasi yang sudah ada
- `tests/Feature/Business/`: `AuthorizationBoundaryIsolationTest`, `CrossBusinessForeignKeyTest`, `MasterDataIsolationTest`, `TransactionStockIsolationTest`, `ReportIsolationTest`, `CrmLoyaltyIsolationTest`, `ImportExportIsolationTest`, `TenantRouteBindingTest`, `BusinessHierarchyServiceTest`, `BusinessBackfillCommandTest`, `UniquenessAndShiftBoundaryTest`, `StoreSettingsIsolationTest`.
- `tests/Feature/Tenancy/`: `BusinessContextMiddlewareTest`, `BusinessMembershipTest`.
- Ownership payable/receivable aktif-vs-foreign dan fail-closed record manual diuji di `AuthorizationBoundaryIsolationTest` (mis. `test_tenant_ownership_resolves_payables_via_purchase_order`, `test_tenant_ownership_resolves_receivables_via_transaction`).

---

## 3. Unified decision register

Format kolom sesuai permintaan. `QS` = status: `verified` | `needs clarification` | `blocked` | `ready for approval`.

### 3.1 Q2 / Q3 / Q14 — Membership & authorization

| ID | Pertanyaan | Fakta repository | Ref | Risiko bila tak diputuskan | Opsi & trade-off | Rekomendasi bersyarat | Keputusan bisnis diperlukan | Dependency | Risiko perubahan | Approval | Acceptance & bukti | QS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Q2** | Apakah `owner` = role membership business, dan `super-admin` tetap operator platform global? | Membership model ada (`BusinessUser`), `owner_user_id` ada tapi tidak memberi hak otorisasi; super-admin bypass Spatie global | `BusinessUser.php`, `Business.php:24,35`, `User.php:100,105` | Ambiguitas otoritas owner; owner tanpa permission efektif, atau super-admin terlalu luas | A) owner=membership + super-admin global (open-decisions rekomendasi); B) owner=role Spatie global | A (kondisional) | Ya: definisi owner vs super-admin | Q3 | Sedang | Product owner | Owner dapat mengelola business-nya; super-admin hanya platform | `ready for approval` |
| **Q2.a** | Apakah `owner_user_id` memberi hak istimewa otorisasi? | Tidak ada policy yang membaca `owner_user_id` | grep Gate/Policy kosong | Ekspektasi owner ≠ realita | A) turunkan owner dari membership `owner`; B) gunakan `owner_user_id` sebagai fallback | A | Ya | Q2 | Rendah | Product owner | Owner selalu punya membership `owner` active | `needs clarification` |
| **Q3** | Apakah role boleh berbeda per business? | Kolom `role` ada di pivot `business_user` (per membership); permission Spatie **global** | `business_user` migrasi `:15`, `config/permission.php:126` | User dengan permission global sensitif tetap bisa memakainya di business mana pun tempat ia member → **escalation** | A) role per-business mengekang permission (butuh pemetaan); B) role global per user | A | Ya: izin lintas-business | Q2, Q14 | Tinggi | Product owner + security | Member `staff` di B tidak bisa memakai permission destruktif hanya karena punya di A | `blocked` |
| **Q3.a** | Bagaimana pencabutan membership memengaruhi akses? | `activeMembershipFor` menolak non-active; `soleActiveMembershipFor` hanya count===1; tanpa context → 403 | `BusinessMembershipResolver:12-50`, `EnsureActiveBusiness:18-23` | Sesi lama / token lama memegang hak | A) re-validasi tiap request (sudah untuk akses business); B) re-issue token abilities saat membership berubah | A (sudah ada) + audit B | Ya (kebijakan token) | Q14 | Sedang | Product owner + security | Token lama tidak melewati isolasi business | `verified` (akses), `needs clarification` (token) |
| **Q14** | Model authz: membership-role, Spatie teams, atau hybrid platform/business? | Spatie `teams=false`; tidak ada team foreign key di migrasi permission; tidak ada Policy | `config/permission.php:126`, `2024_06_13_082620_create_permission_tables.php:46` | Rework besar bila salah pilih | A) membership-role (tanpa teams); B) Spatie teams (`team_id=business_id`); C) hybrid platform vs business | Untuk kebutuhan saat ini: **A**; evaluasi **B** bila perlu permission custom per business | Ya: strategi otorisasi | Q2, Q3 | Tinggi | Product owner + security | Isolasi otorisasi lintas business terbukti via test | `ready for approval` |
| **Q14.a** | Bila teams dipilih, siapkah migrasi Spatie teams? | Migrasi permission sudah punya cabang `teams` (`:46`) tapi tidak aktif; perlu backfill `team_id` | `2024_06_13_082620_create_permission_tables.php:33,46-48` | Migrasi salah bisa melumpuhkan otorisasi | A) aktifkan teams + backfill; B) hindari teams | Tunda sampai Q14 final | Ya | Q14 | Kritis | Product owner | Role/permission ter-scope dan test lulus | `blocked` |

### 3.2 Q7 / Q9 / Q15 — Legacy ownership & financial records

| ID | Pertanyaan | Fakta repository | Ref | Risiko bila tak diputuskan | Opsi & trade-off | Rekomendasi bersyarat | Keputusan bisnis diperlukan | Dependency | Risiko perubahan | Approval | Acceptance & bukti | QS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Q7** | Strategi ownership data legacy (existing = satu business default, atau pemecahan)? | `business:backfill` dry-run; butuh `--apply` + `--confirm-legacy-ownership`; "refusing to guess ownership" bila >1 business | `BusinessBackfillCommand.php:14-19,66-82,117-120` | Backfill salah → kepemilikan data permanen salah | A) satu business default untuk semua data legacy (open-decisions rekomendasi); B) pemecahan manual per record | A untuk instalasi existing | Ya: bentuk backfill | — | Kritis | Product owner (+ DBA) | Semua `business_id` terisi & terverifikasi; rollback tersedia | `ready for approval` |
| **Q7.a** | Apakah ownership payables/receivables ikut backfill dari parent? | Ownership via parent; record tanpa parent fail-closed | `TenantOwnership:73-76,139-142` | Record manual tetap tak terlihat / tak bisa dibayar | A) parent-only fail-closed; B) tambah `business_id` nullable + backfill dari parent | B (hybrid) jangka panjang; A sementara | Ya | Q7, Q9 | Sedang | Product owner | Aging per business cocok sebelum/sesudah | `blocked` |
| **Q9** | Kebijakan record payable/receivable **manual** (tanpa parent)? | Manual payable: `PayableController:87`; manual receivable: tidak ada endpoint `store` (hanya legacy/import); read fallback bila ≤1 outlet | `PayableController.php:71-89,222-240`, `ReceivableService.php:112-131` | Ada data finansial tanpa pemilik yang terbukti | A) kepemilikan eksplisit saat create; B) relasi ke entitas ber-ownership; C) workflow rekonsiliasi legacy | A untuk data baru + C untuk legacy | Ya | Q7 | Tinggi | Product owner + finance | Setiap record punya owner terbukti atau berstatus unresolved | `blocked` |
| **Q9.a** | Bedakan kemampuan read/create/update/pay terhadap record manual | Read: fallback `orWhereDoesntHave` bila ≤1 outlet aktif; pay: `canUseWarehouse(null)` → legacy bypass; create: manual ada | `PayableController:229,235-240`, `OutletAccessService:232-236` | Baca/bayar lintas-business saat kondisi ≤1 outlet | Lihat Q9 | Fail closed untuk aksi finansial sampai Q9 | Ya | Q7 | Tinggi | Product owner + finance | Aksi finansial menolak record ambigu | `blocked` |
| **Q15** | Definisi & penanganan orphan (parent hilang/tidak valid)? | Parent null / dangling FK → `entityBelongsToActiveBusiness` false; table tak dikenal → true | `TenantOwnership:111-113,139-142,145-146` | Integritas referensial finansial | A) fail closed + inventaris; B) perbaiki FK; C) arsip | A + inventaris read-only | Ya | Q7, Q9 | Sedang | Product owner | Orphan terinventarisasi; tidak ada auto-hapus | `blocked` |
| **Q15.a** | Klasifikasi orphan yang benar-benar ada | Belum ada kueri produksi; hanya analisis kode | (belum diverifikasi) | Salah klasifikasi → salah kebijakan | 5 kelas (parent valid / manual / parent hilang / ambigu / tak terklasifikasi) | Jalankan inventaris read-only dulu | Ya (setelah inventaris) | Q7 | Rendah (audit) | Product owner | Laporan jumlah per kelas | `needs clarification` |

### 3.3 Q4 — Tenant-scoped uniqueness

| ID | Pertanyaan | Fakta repository | Ref | Risiko bila tak diputuskan | Opsi & trade-off | Rekomendasi bersyarat | Keputusan bisnis diperlukan | Dependency | Risiko perubahan | Approval | Acceptance & bukti | QS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Q4** | Scope uniqueness master data: global / per-business / per-outlet? | Sebagian besar unique **global**; `business_id` nullable di master data | §2.4, `2026_10_10_160000_add_business_id_to_master_data_tables.php` | Composite index salah → constraint lemah/kuat keliru; race | A) per-business `(business_id, X)`; B) per-outlet; C) tetap global | A untuk sebagian besar; per-outlet untuk outlet-scoped | Ya: scope per field | Q7 (backfill non-null) | Tinggi | Product owner + DBA | Duplikat dalam scope sesuai kebijakan | `blocked` |
| **Q4.a** | `categories.name` | **Tidak ada** unique DB; web `:52-56` hanya `required`; API pakai `TenantUnique` | `Apps/CategoryController:52-56`, `Api/CategoryController:37,65` | Inkonsistensi web vs API | A) tenant-scoped app-level (sudah di API); B) web ikut tenant-scoped; C) index DB per-business | A+B; C menunggu Q4 | Ya (bila dedup DB) | Q4 | Rendah | Product owner | Web & API konsisten; tanpa index conflict | `ready for approval` (app-level) |
| **Q4.b** | `products.barcode` / `products.sku` | Unique global di DB | `2024_06_13_131744:20`, `2025_12_28_105534:15` | Dua business tak bisa pakai barcode sama | A) `(business_id, barcode)`; B) global | A | Ya | Q4, Q7 | Tinggi | Product owner + DBA | Backfill non-null dulu; composite dibuat | `blocked` |
| **Q4.c** | `price_lists.slug`, `units.code`, `warehouses.code`, `outlets.code` | Unique global | `2026_06_22_000015:14`, `..._000010:14`, `..._000001:13`, `2026_09_14_000001:13` | Tabrakan nama antar business | A) composite per-business | A | Ya | Q4, Q7 | Sedang–Tinggi | Product owner | Composite index + validasi app | `blocked` |
| **Q4.d** | `customers.member_code`, `customer_vouchers.code` | Unique global (member_code nullable) | `2026_05_05_000102:13`, `2026_05_05_000104:14` | Tabrakan kode antar business | A) composite per-business/outlet | A | Ya | Q4, Q7 | Sedang | Product owner | Composite index | `blocked` |
| **Q4.e** | `customer_segments.slug` | Unique global; **tidak ada ownership** (`business_id`) | `2026_05_06_000110:14`, `CustomerSegmentController:205` | Segmen tanpa tenant → kebocoran | A) tentukan ownership dulu; B) biarkan global | Tentukan ownership sebelum index | Ya | Q4 (+ ownership segmen) | Sedang | Product owner | Ownership segmen jelas | `blocked` |
| **Q4.f** | `document_number` (PO/GR/transfer/supplier_return/sales_return/stock_opname) | Unique global; nomor di-generate dari query tanpa scope business | `2026_05_04_000001:14`, `2026_05_04_000002:15`, `2026_06_22_000006:15`, `2026_05_05_000001:16`, `2026_05_02_000000:13`, `2026_05_01_231134:16` | Nomor lintas business (info minor), atau tabrakan bila di-scope tanpa index baru | A) biarkan global (aman tabrakan); B) per-business + composite | A (default), B hanya bila perlu sekuens terpisah | Ya | Q4, Q11 | Sedang | Product owner | Tidak ada tabrakan; sekuens sesuai kebijakan | `ready for approval` |
| **Q4.g** | `transactions.client_uuid` (open-decisions Q11) | Lookup global; unique nullable | `2026_09_10_000001:12`, `PosApiController:743,878` | Idempotensi offline bisa tabrakan antar business | A) `(business_id, client_uuid)`; B) global | A bila multi-business aktif | Ya | Q4 | Sedang | Product owner | Sinkronisasi offline idempoten per business | `blocked` |
| **Q4.h** | `settings(key, outlet_id)` | Unique komposit; tanpa `business_id` | `2026_09_14_000004:17-18` | Dua business tak punya default global terpisah | Lihat Q5 | Menunggu Q5 | Ya | Q5 | Sedang | Product owner | Unique sesuai resolusi Q5 | `blocked` |

> **Catatan Q4:** menurunkan scope (global → per-business) **melemahkan** constraint, sehingga tidak menciptakan duplikat baru. Risiko sesungguhnya: `business_id` **NULL** pada composite unique (MySQL memperlakukan NULL distinct) → baris ber-`business_id` NULL bisa duplikat. Karena itu **backfill non-null wajib mendahului** composite index.

### 3.4 Q5 — Settings & store profile

| ID | Pertanyaan | Fakta repository | Ref | Risiko bila tak diputuskan | Opsi & trade-off | Rekomendasi bersyarat | Keputusan bisnis diperlukan | Dependency | Risiko perubahan | Approval | Acceptance & bukti | QS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Q5** | Scope settings: global / per-business / per-outlet (dan inheritance)? | `(key,outlet_id)`, tanpa `business_id`; `getForOutlet` fallback global | `Setting.php:23-96`, `2026_09_14_000004` | Konfigurasi bocor lintas business; default global tak bisa dipisah | A) global; B) per-outlet saja; C) tambah `business_id` + hierarki; D) hybrid key-based | C (hierarki: outlet → business → platform) | Ya: klasifikasi key | Q7 | Tinggi | Product owner | Key sesuai kelas; fallback jelas | `blocked` |
| **Q5.a** | Store profile (`store_name/logo/address/phone/email/website/city`) | Dibaca/ditulis per-outlet via `activeOutlet` (business-restricted); Inertia per-outlet | `SettingController:65-74,122-128`, `HandleInertiaRequests:211-223` | Fallback global dapat menampilkan profil business lain bila outlet belum diset | A) per-outlet (biasanya sudah); B) per-business eksplisit | A + tambah `business_id` bila bisnis butuh default | Ya | Q5 | Sedang | Product owner | Profil per business terisolasi; test ada | `ready for approval` (perbaikan minor) |
| **Q5.b** | `store_npwp`, `store_nib` | **Ditulis global** (`Setting::set`), dibaca global | `SettingController:129-130,72-73` | Admin A menimpa identitas legal business lain | A) per-business; B) per-outlet | A (identitas legal = business) | Ya | Q5, Q7 | Tinggi | Product owner + finance | NPWP/NIB tidak bocor/tertimpa | `blocked` |
| **Q5.c** | `tax_default_rate` | Ditulis global; dibaca `getForOutlet` (fallback global) | `SettingController:131`, `TaxService:12` | Pajak business lain terpakai | A) per-business; B) per-outlet | Keputusan (bisa per-outlet) | Ya | Q5, Q7 | Tinggi | Product owner + finance | Tarif pajak per business/outlet | `blocked` |
| **Q5.d** | `discount_approval_threshold`, `discount_approval_percent_threshold` | Dibaca global | `Transaction.php:170-171` | Kebijakan approval sama untuk semua business | A) per-business | A | Ya | Q5 | Sedang | Product owner | Ambang approval per business | `blocked` |
| **Q5.e** | `loyalty_*` (enable/earn/redeem/tier) | Dibaca & ditulis global (`setMany`) | `LoyaltyService:30-92` | Kebijakan loyalty tercampur lintas business | A) per-business | A | Ya | Q5 | Sedang | Product owner | Loyalty per business | `blocked` |
| **Q5.f** | `printer_*`, `wa_*`, `monthly_sales_target` | Per-outlet (baca/tulis `*ForOutlet`) | `SettingController:161-176,244-276` | Relatif aman; bergantung resolusi `activeOutlet` | A) tetap per-outlet | A | Minimal | Q5 | Rendah | Product owner | Tetap per-outlet | `verified` |
| **Q5.g** | Settings pada proses tanpa tenant context (scheduler/job) | Command terjadwal tanpa context; tidak ada `app/Jobs` | `routes/console.php:11-14` | Job memakai nilai global lintas business | A) iterasi per business + set context; B) parameter global eksplisit | A | Ya | Q5, Q7, Q8 | Tinggi | Product owner | Job deterministik per business | `blocked` |

### 3.5 Cross-cutting

| ID | Pertanyaan | Fakta | Ref | Status |
|---|---|---|---|---|
| **X1** | Penomoran Q: task/ADR vs open-decisions | Dua skema hidup bersamaan | § atas, `multi-business-open-decisions.md:14-31`, ADR-02/03/04 | `needs clarification` |
| **X2** | Definisi transaksi "completed" (open-decisions Q1) | Hanya `payment_status`; Dashboard hitung semua, shift pakai `paid` | `DashboardController:33`, `CashierShiftService:88,97` | `needs clarification` |
| **X3** | `audit_logs.business_id` (open-decisions Q12) | Rekomendasi tambah | open-decisions | `blocked` (Q2/Q7) |
| **X4** | Global scope vs scoping eksplisit (open-decisions Q13) | `BelongsToBusiness` global scope selektif | `BelongsToBusiness.php:24-48` | `verified` (pendekatan ada) |

---

## 4. Dependency map

```
Q7 (bentuk backfill: existing=satu business)
 ├── Q4  (composite unique butuh business_id non-null)
 ├── Q5  (settings per-business butuh backfill baris legacy)
 ├── Q9  (kebijakan manual financial)
 └── Q15 (orphan & dangling FK)
        └── Q9 (aksi finansial vs record ambigu)

Q2 (owner vs super-admin)
 ├── Q3 (role per-business)
 │     └── Q14 (model otorisasi: membership vs teams)
 └── X3 (audit_logs.business_id)

Q4 ⇄ Q11 (client_uuid)  [keduanya soal scope constraint]
Q5 ⇄ Q8 (job/scheduler perlu context)
```

**KESIMPULAN TEKNIS:** **Q7** adalah root paling berpengaruh (bentuk backfill memengaruhi Q4/Q5/Q9/Q15). **Q2** adalah root untuk seluruh otorisasi (Q3→Q14). Q4 dan Q5 relatif paralel satu sama lain, tetapi keduanya bergantung pada Q7 untuk backfill.

---

## 5. Prioritized roadmap

### Fase A — Audit read-only (fase ini)
- **Tujuan:** verifikasi ADR/kode/schema/test; susun register + dependency map.
- **Blocker:** — (semua tetap terbuka).
- **Dependency:** —
- **Artefak:** dokumen ini.
- **Approval:** tidak ada.
- **Lanjut bila:** register disetujui sebagai basis keputusan.
- **Berhenti bila:** ditemukan perubahan yang menuntut implementasi (tidak; tidak ada).

### Fase B — Keputusan arsitektur (PERLU KEPUTUSAN)
- **Tujuan:** jawab Q2/Q3/Q14, Q7/Q9/Q15, Q4, Q5 secara eksplisit (tanggal + penanggung jawab).
- **Blocker:** seluruh Q.
- **Dependency:** Fase A.
- **Artefak:** keputusan tertulis di `multi-business-open-decisions.md` (+rekonsiliasi penomoran X1).
- **Approval:** product owner (+security/finance/DBA sesuai baris).
- **Lanjut bila:** minimal Q7, Q2, Q3, Q4, Q5 disetujui.
- **Berhenti bila:** ada keputusan yang saling bertentangan.

### Fase C — Desain implementasi untuk disetujui (belum implementasi)
- **Tujuan:** rancangan per blocker: file/komponen, migration draft + urutan, audit duplikasi, rencana backfill/rekonsiliasi, idempotensi, rollback, observability, test plan.
- **Blocker:** Q7, Q4, Q5, Q2/Q3/Q14.
- **Dependency:** Fase B.
- **Artefak:** dokumen desain + migration **draft** (belum dijalankan) + query audit read-only.
- **Approval:** product owner + DBA + security.
- **Lanjut bila:** desain & rollback ditinjau.
- **Berhenti bila:** dependensi tidak lengkap.

### Fase D — Implementasi terkontrol (instruksi terpisah)
- **Tujuan:** eksekusi sesuai approval dengan lingkup eksplisit.
- **Blocker:** semua yang di atas.
- **Dependency:** Fase C disetujui.
- **Artefak:** kode/migration/test sesuai scope.
- **Approval:** terpisah untuk (a) migration, (b) backfill, (c) perubahan produksi, (d) aktivasi flag, (e) deployment.
- **Lanjut bila:** tiap gate disetujui terpisah.
- **Berhenti bila:** approval tidak menyebut lingkup.

**Pekerjaan read-only yang bisa paralel TANPA menunggu keputusan:** audit duplikasi Q4 (§6), inventaris orphan/dangling-FK financial (§6), snapshot settings + pembaca/penulis (sudah sebagian di sini), ekspor matriks membership/role, dan verifikasi cakupan test isolasi.

**Yang tetap DIBLOKIR sampai approval:** setiap composite unique migration (Q4), perubahan schema/baca-tulis settings (Q5), penegakan otorisasi (Q2/Q3/Q14), backfill/assignment ownership financial (Q7/Q9/Q15), isolasi job/scheduler, aktivasi flag, dan deployment.

---

## 6. Security & data-integrity risks

- **Cross-tenant read (financial) — SEDANG (bersyarat):** fallback `orWhereDoesntHave` pada payables/receivables memasukkan record tanpa parent saat `Outlet::active()->count() <= 1`. Saat flag on, kondisi ini memungkinkan record manual business lain tampil. Ref: `PayableController:229`, `ReceivableService:124`.
- **Cross-tenant financial action — SEDANG:** `canUseWarehouse(user, null)` → `legacySingleOutletBypass()` true saat ≤1 outlet → show/pay record manual bisa lolos. Ref: `OutletAccessService:232-236`, `PayableController:235-240`.
- **Authorization over-privilege — TINGGI:** permission Spatie global; role membership tidak mengekang; user dengan permission sensitif di business A dapat memakainya di business B tempat ia member. Ref: `User.php:105`, `config/permission.php:126`.
- **Settings cross-tenant write — TINGGI:** `store_npwp`/`store_nib`/`tax_default_rate` ditulis global; admin satu business menimpa nilai global. Ref: `SettingController:129-131`.
- **Settings cross-tenant read — SEDANG:** pembaca global (`Api/AuthController:122-124`, `LoyaltyService`, `Transaction:170-171`) memakai satu baris global.
- **Uniqueness probe — RENDAH:** sebagian besar unique masih global → validasi `unique:` dapat membocorkan keberadaan nilai business lain; `categories.name`/`customers.no_telp` sudah dimitigasi via `TenantUnique`.
- **Orphan/dangling FK — SEDANG:** parent hilang → fail closed (aman), tetapi record tetap tidak dapat diklasifikasikan; perlu inventaris.
- **Info leak minor:** `document_number` sekuens global (bukan celah otorisasi).

---

## 7. Open questions untuk pemilik keputusan

Tanpa jawaban yang diasumsikan:

1. **Q7:** Apakah seluruh instalasi existing dianggap **satu business default**, dan apa nama/ID business default itu? (menentukan seluruh backfill)
2. **Q2:** Apakah `owner` **hanya** role membership, dan `super-admin` tetap operator platform? Apakah `owner_user_id` memberi hak istimewa?
3. **Q3:** Bolehkah role berbeda per business, dan **apakah role membership membatasi permission Spatie**? Jika ya, pemetaan role→permission yang disetujui apa?
4. **Q14:** Membership-role, Spatie teams, atau hybrid? Jika teams, siapa yang melakukan backfill `team_id`?
5. **Q9:** Apakah record payable/receivable **manual** diizinkan? Jika ya, bagaimana ownership ditetapkan saat pembuatan? Jika tidak, apakah harus fail closed permanen?
6. **Q7.a:** Apakah payables/receivables mendapatkan kolom `business_id` (backfill dari parent) atau tetap parent-only?
7. **Q15:** Bagaimana perlakuan record orphan/dangling (arsip, perbaiki FK, atau fail closed permanen)? Siapa yang menandatangani keputusan?
8. **Q4:** Untuk setiap field, scope final: **global / per-business / per-outlet**? (minimal: barcode, sku, price_lists.slug, units/warehouses/outlets.code, member_code, customer_vouchers.code, customer_segments.slug, document_number, categories.name)
9. **Q4.e:** Apakah `customer_segments` diberi ownership business?
10. **Q5:** Klasifikasi key: mana **global**, **per-business**, **per-outlet**? Apakah `store_npwp`/`store_nib`/`tax_default_rate` per-business atau per-outlet?
11. **Q5.g/Q8:** Apakah job/scheduler harus iterasi per business? Batas infra (shared hosting) — queue atau sinkron?
12. **X1:** Penomoran Q mana yang menjadi acuan resmi (task/ADR atau open-decisions)?
13. **X2 (Q1):** Definisi transaksi "completed" — `payment_status='paid'`?

---

## 8. Test plan & acceptance criteria (tanpa perubahan implementasi)

### 8.1 Read-only (dapat dijalankan tanpa approval keputusan)
- **Q4 duplicate audit:** untuk setiap field, kueri `GROUP BY <scope-cols> HAVING COUNT(*) > 1` (read-only). Untuk field yang sudah global-unique, hasil diharapkan kosong; fokus pada `categories.name` dan field tanpa index.
- **Q7/Q9/Q15 orphan inventory:** hitung payables `purchase_order_id IS NULL`; receivables `transaction_id IS NULL`; hitung FK dangling (`purchase_order_id NOT IN (SELECT id …)`); klasifikasi 5 kelas.
- **Q5 settings inventory:** ekspor seluruh baris `settings` + pembaca/penulis (sudah terpetakan di §2.5); pastikan jumlah key yang ditulis global.
- **Membership matrix:** daftar user × business × role × status (read-only).

### 8.2 Acceptance criteria per keputusan (setelah disetujui, di Fase C/D)
- **Q2/Q3/Q14:** test membuktikan member `staff` di business B tidak dapat memakai permission destruktif; owner dapat mengelola business-nya; super-admin hanya platform. Bukti: `tests/Feature/Business/*`, `tests/Feature/Security/*`.
- **Q7/Q9/Q15:** aging/statement per business cocok sebelum & sesudah backfill; aksi finansial menolak record ambigu; orphan terinventarisasi tanpa auto-hapus. Bukti: test ownership + laporan rekonsiliasi.
- **Q4:** untuk tiap field, duplikat dalam scope sesuai kebijakan; backfill non-null sebelum composite index; rollback mengembalikan index global. Bukti: test unique + migration rollback test.
- **Q5:** dua business dapat memiliki nilai berbeda untuk key business-level tanpa saling menimpa; key platform tetap global. Bukti: test settings isolation (sudah ada untuk jalur outlet-scoped).

### 8.3 Test yang sudah ada (jangan diubah)
- Ownership financial fail-closed: `AuthorizationBoundaryIsolationTest` (`test_tenant_ownership_resolves_payables_via_purchase_order`, `..._receivables_via_transaction`, `test_supplier_return_rejects_payable_of_another_business`).
- FK lintas business: `CrossBusinessForeignKeyTest`.
- Isolasi master data: `MasterDataIsolationTest`.
- Settings outlet-scoped: `StoreSettingsIsolationTest`.
- Uniqueness + shift boundary: `UniquenessAndShiftBoundaryTest`.

---

## 9. Safety compliance evidence

Berdasarkan pemeriksaan yang benar-benar dilakukan pada tahap ini:

| Batas | Status | Bukti |
|---|---|---|
| Mengaktifkan `multi_business.enabled` | **Tidak** | `config/multi_business.php:16` default `false`; `.env`/`.env.example` tidak mendefinisikan `MULTI_BUSINESS_ENABLED` (grep kosong) |
| Menjalankan migration | **Tidak** | Tidak ada perintah migrate dijalankan; `git status database/migrations/` hanya menampilkan file lama (tanggal 2026-10-10) |
| Mengubah schema | **Tidak** | Tidak ada file migration dibuat/diubah pada audit ini |
| Backfill / ubah data | **Tidak** | Hanya `grep`/`cat`/`sed`/`git status` (read-only) |
| Mengubah kode aplikasi | **Tidak** | `git status` tidak menunjukkan perubahan baru dari audit ini |
| Mengubah test | **Tidak** | Tidak ada test dibuat/diubah pada audit ini |
| Mengubah authorization/membership/role/settings/uniqueness/ownership | **Tidak** | Tidak ada edit |
| Deployment / konfigurasi produksi | **Tidak** | Tidak ada perintah deploy |
| Build 5 / AI | **Tidak** | Tidak ada |

**Catatan transparansi:** working tree **sudah** berisi perubahan dari fase sebelumnya (Build 4A–4E + ADR phase + dokumen). Audit ini **tidak** mengubahnya; hanya menambahkan dokumen ini.

---

## 10. Limitations

1. **Tidak menjalankan test suite** pada audit ini. Angka test yang dikutip berasal dari run fase sebelumnya; belum diverifikasi ulang di sini.
2. **Tidak mengakses database produksi/dev** untuk audit duplikat, orphan, atau snapshot settings. Kueri di §8.1 bersifat usulan read-only, belum dijalankan.
3. **Ketidaksesuaian penomoran Q** (X1) belum direkonsiliasi oleh pemilik keputusan.
4. **Ownership `customer_segments`** tidak dapat diverifikasi (tidak ada `business_id`) → Q4.e `blocked`.
5. **State `.env` produksi** tidak dapat diverifikasi selain dari repo; hanya `.env`/`.env.example` yang diperiksa.
6. **Cakupan caller pengaturan** dipetakan dari grep; kemungkinan ada pembaca tidak langsung (mis. via cache konfigurasi) yang tidak terdeteksi.
7. **`business:backfill`** tidak dijalankan (sesuai batasan), sehingga jumlah baris `business_id` NULL tidak diketahui.

---

## 11. Penutup — keputusan paling mendesak, kerja paralel, dan yang diblokir

**Paling mendesak untuk disetujui (root dependency):**
1. **Q7** — bentuk backfill (existing = satu business default). Memengaruhi Q4, Q5, Q9, Q15.
2. **Q2 + Q3** — model otorisasi owner/role (root untuk Q14 dan seluruh penegakan akses).
3. **Q5** — klasifikasi settings (kebocoran konfigurasi nyata; `store_npwp`/`store_nib`/`tax_default_rate` sudah menulis global).
4. **Q4** — scope uniqueness per field sebelum rancangan composite index difinalisasi.

**Read-only yang dapat berjalan paralel sekarang:** audit duplikasi Q4, inventaris orphan/dangling-FK financial (Q7/Q9/Q15), snapshot settings + pembaca/penulis (Q5), dan ekspor matriks membership/role (Q2/Q3/Q14).

**Tetap diblokir sampai approval:** semua composite unique migration (Q4), perubahan schema/baca-tulis settings (Q5), penegakan otorisasi (Q2/Q3/Q14), backfill/assignment ownership financial (Q7/Q9/Q15), isolasi job/scheduler, aktivasi feature flag, dan deployment.

**Status deliverable:** `Complete with approval blockers`. Tidak ada keputusan bisnis yang dibuat atas nama pemilik produk; tidak ada implementasi.

---

## 12. Catatan fase implementasi aman (Phase-1 containment) — 2026-10-11

Bagian ini mencatat **perubahan kode yang benar-benar dilakukan** pada fase ini beserta test yang dijalankan. Tidak mengubah keputusan yang tercatat di §3; hanya containment teknis yang tidak memerlukan keputusan bisnis baru.

### 12.1 Workstream A — kebocoran settings (Q5)

- **File:** `app/Models/Setting.php`, `app/Exceptions/TenantSettingScopeException.php`.
- **Sebelum:** `Setting::get()`/`set()` selalu memakai baris global (`outlet_id = null`). Saat tenancy aktif, business A membaca/menimpa baris global yang sama dengan business B (`store_npwp`, `store_nib`, `tax_default_rate`, `discount_approval_*`, `loyalty_*`), termasuk via fallback `getForOutlet()`.
- **Sesudah:** saat `multi_business.enabled` `true`, hanya key platform (`app_setup_completed`, `setup_warehouse_id`) yang boleh memakai baris global; key tenant lain **fail closed** — baca mengembalikan default, tulis menolak dengan `TenantSettingScopeException`. Saat flag off, perilaku 100% tidak berubah.
- **Tanpa migration** (tidak ada kolom `business_id`; itu tetap Q5).
- **Test:** `tests/Feature/Business/SettingTenantScopeTest.php` (6 test).

### 12.2 Workstream B — fallback financial berbasis jumlah outlet (Q7/Q9/Q15)

- **File:** `app/Services/OutletAccessService.php` (`legacySingleOutletBypass`), `app/Services/ReceivableService.php`, `app/Services/PayableAgingService.php`, `app/Http/Controllers/Apps/PayableController.php`.
- **Sebelum:** `Outlet::active()->count() <= 1` dipakai sebagai bukti ownership; manual payable/receivable tanpa parent ikut tampil dan bisa diakses walau tidak ada ownership.
- **Sesudah:** saat `multi_business.enabled` `true`, fallback single-outlet dimatikan sehingga record tanpa parent **fail closed** pada jalur baca (aging, index) maupun mutasi (show/pay). Saat flag off, perilaku legacy tetap.
- **Tidak ada ownership baru** yang dibuat untuk manual payables/receivables.
- **Test:** `tests/Feature/Business/FinancialOwnershipFallbackTest.php` (13 test).

### 12.3 Workstream C — penegakan membership (Q2/Q3/Q14)

- **Tidak ada perubahan kode.** `ResolveBusinessContext` + `EnsureActiveBusiness` ternyata sudah fail-closed: membership `invited`/`suspended`/`revoked` atau tidak ada tidak menghasilkan konteks, dan route ber-`business.required` mengembalikan 403. Super-admin bypass tetap (tidak diubah).
- **Test ditambahkan:** `tests/Feature/Tenancy/BusinessContextMiddlewareTest.php` — absent/suspended/revoked membership (3 test).

### 12.4 Workstream D — uniqueness tenant-aware (Q4)

- **Perbaikan aman:** `app/Http/Controllers/Apps/CategoryController.php` (web `store` + `update`) tidak punya validasi keunikan sama sekali. Ditambahkan `TenantUnique::for('categories','name')` / `...(', 'name', $category->id)` — konsisten dengan API (ADR-03). Tidak mengubah index DB dan tidak menyentuh field yang menunggu Q4 (`barcode`/`sku`/`slug`/`code`).
- **Verifikasi:** `TenantUnique` sudah dipakai pada semua jalur create/update `customers.no_telp` (web `CustomerController`, `MemberController`, `Api/CustomerController`) dengan `ignore` yang benar.
- **Bypass yang dicatat (belum diperbaiki):** `app/Imports/CustomersImport.php` (batch insert) tidak menegakkan keunikan `no_telp`; `app/Imports/ProductsImport.php` memakai `updateOrCreate(['barcode'])` yang, bila business lain sudah memiliki barcode sama, akan menabrak index global (Q4) — bukan kebocoran ownership.
- **Test:** 4 test web ditambahkan ke `tests/Feature/Business/UniquenessAndShiftBoundaryTest.php`.
- **Audit duplikat:** query read-only disiapkan di `multi-business-readonly-audit-queries.md`; **belum dijalankan** (tidak ada DB aman).

### 12.5 Workstream E — inventaris orphan/dangling (Q7/Q9/Q15)

- Query read-only disiapkan (`multi-business-readonly-audit-queries.md` §2). **Belum dijalankan**; jumlah record belum terverifikasi.
- **Verifikasi dry-run `business:backfill`:** mengikuti `--apply` sebagai default dry-run; penulisan hanya terjadi bila `--apply` **dan** `--confirm-legacy-ownership`; dengan >1 business tanpa `--business` ia menolak. Mode dry-run tidak menulis data. Tidak dijalankan pada data nyata.

### 12.6 Workstream F — scheduler & job

- `app/Jobs/` **tidak ada**. Scheduler: `routes/console.php` (`crm:sync-segments`, `crm:generate-reminders`, `reorder:generate`, `transactions:expire`).
- `reorder:generate` dan CRM mengiterasi model ber-`BelongsToBusiness`; tanpa konteks + flag on → fail closed (`1=0`) → no-op. Tidak ada iterasi per-business (belum ada).
- **Perbaikan aman:** `app/Console/Commands/ExpirePendingTransactionsCommand.php` kini fail closed (return FAILURE) saat tenancy aktif tanpa konteks, sehingga tidak ada transaksi ditandai gagal tanpa restock produk (produk business-scoped tidak akan resolve tanpa konteks).
- **Test:** `tests/Feature/Business/SchedulerTenantContextTest.php` (2), `tests/Feature/Console/ExpirePendingTransactionsTest.php` (+1).
- **Blocker:** iterasi per-business eksplisit (set konteks → jalankan → bersihkan) untuk command tenant-scoped belum didesain/disetujui.

### 12.7 Ringkasan test yang benar-benar dijalankan

```
php artisan test tests/Feature/Business tests/Feature/Tenancy --compact
→ 161 passed (555 assertions)   [baseline sebelum fase: 133 passed / 486 assertions]

php artisan test tests/Feature/Console/ExpirePendingTransactionsTest.php
→ 8 passed (33 assertions)

php artisan test --compact        (seluruh suite)
→ 618 passed (2628 assertions), 0 failed, 117.44s   [sebelum fase: 589 passed]
```

### 12.8 Batas keselamatan yang tetap dipatuhi

`multi_business.enabled` tetap `false` (default config, tidak diubah di `.env`). Tidak ada migration dijalankan/dibuat, tidak ada perubahan schema, tidak ada backfill, tidak ada akses/ubah data produksi, tidak ada deployment, tidak ada Build 5/AI. Perubahan kode hanya containment yang inert saat flag off.

---

## 13. Owner Decisions — Decision Log (APPROVED — DOCUMENTATION ONLY)

- **Tanggal keputusan:** 2026-10-11
- **Status:** Setiap baris di bawah ditandai **`APPROVED — DOCUMENTATION ONLY`**. Ini **bukan** izin implementasi. Tidak ada migration, backfill, perubahan schema/data, aktivasi feature flag, atau deployment yang dijalankan pada bagian ini.
- **Sumber:** jawaban eksplisit pemilik proyek atas decision gate (Bagian 1–10). Dicatat **tanpa interpretasi tambahan**; catatan teknis hanya sebagai konsekuensi/risiko/dependency.
- **Penomoran:** mengikuti hasil **GOV-01 (X1.1 = C)** — ID stabil + cross-reference ke ID lama. Register §3 tetap memakai ID lama; gunakan tabel pemetaan di §13.9.

### 13.1 Authorization (AUTH)

| ID | Keputusan owner | Konsekuensi teknis | Migration / data impact | Risiko | Dependency |
|---|---|---|---|---|---|
| **AUTH-01** (Q2) | **C — Hybrid**: `BusinessUser` membership menentukan membership & role di dalam business; Spatie menentukan capability/permission; platform super-admin punya jalur platform-level terpisah & eksplisit. | Business authz = membership + role; capability tetap Spatie; super-admin bypass tetap terpisah. | Tidak ada (keputusan model). Implementasi butuh perubahan policy/guard pada task terpisah. | Sedang: batas owner vs super-admin harus eksplisit di UI/policy. | AUTH-02, AUTH-03 |
| **AUTH-02** (Q3) | **A — Role per business membership**: user boleh punya role berbeda di business berbeda. | `business_user.role` menjadi scope role per business. | Perlu enforcement agar permission global tidak melewati batas business (design task terpisah). | **Tinggi** (lihat §13.6 residual risk): Spatie `teams=false`, jadi permission global belum terbatas oleh membership. | AUTH-01, AUTH-03 |
| **AUTH-03** (Q14) | **C — Hybrid**: membership = tenant membership + role scope; permission engine = capability. **Jangan aktifkan Spatie teams** sampai desain implementasinya disetujui & diuji. | Tetap `teams=false`; reconcilasi capability vs membership ditunda ke desain. | Tidak ada sekarang. Teams (jika nanti) = migrasi + backfill `team_id`, butuh approval terpisah. | Tinggi bila enforcement tidak segera menyusul AUTH-02. | AUTH-01, AUTH-02 |

**Konsistensi AUTH-01/02/03:** **KONSISTEN** — ketiganya hybrid membership-first dengan `teams=false`. Tidak ada kontradiksi antar jawaban.

### 13.2 Uniqueness (UNIQUE) — Q4

| ID | Keputusan owner | Target constraint | Migration / data impact | Risiko | Dependency |
|---|---|---|---|---|---|
| **UNIQUE-01** (Q4.1) | **B — Per-business** | `(business_id, barcode)` | Ganti index unik global `products.barcode` → composite. | Tinggi: butuh `business_id` non-null dulu. | FIN-01 (backfill non-null) |
| **UNIQUE-02** (Q4.2) | **B — Per-business** | `(business_id, sku)` | Ganti index unik global `products.sku`. | Tinggi | FIN-01 |
| **UNIQUE-03** (Q4.3) | **B — Per-business** | `(business_id, slug)` | Ganti index unik global `price_lists.slug`. | Sedang–Tinggi | FIN-01 |
| **UNIQUE-04** (Q4.4) | **A — Global** | tetap `units.code` global | Tidak ada perubahan. | Rendah | — |
| **UNIQUE-05** (Q4.5) | **B — Per-business** | `(business_id, code)` | Ganti index unik global `warehouses.code`. | Tinggi (warehouse = ownership path) | FIN-01 |
| **UNIQUE-06** (Q4.6) | **B — Per-business** | `(business_id, code)` | Ganti index unik global `outlets.code`. | Tinggi (outlet = ownership path) | FIN-01 |
| **UNIQUE-07** (Q4.7) | **B — Per-business** | `(business_id, member_code)` | Ganti index unik global `customers.member_code`. | Sedang | FIN-01 |
| **UNIQUE-08** (Q4.8) | **B — Per-business** | `(business_id, code)` | **Butuh kolom `business_id` baru** di `customer_vouchers` (saat ini tidak ada) + backfill. | Tinggi | FIN-01 + approval schema terpisah |
| **UNIQUE-09** (Q4.9) | **B — Per-business** | `(business_id, slug)` | **Butuh kolom `business_id` baru** di `customer_segments` (saat ini tidak ada) + backfill. | Tinggi | FIN-01 + approval schema terpisah |
| **UNIQUE-10** (Q4.10) | **B — Per-business** (semua jenis dokumen) | `(business_id, document_number)` / `(business_id, code)` per tabel | Ganti index unik global PO/GR/SR/ST; `sales_returns.code`, `stock_opnames.code`. **`payables.document_number` saat ini hanya index (tidak unik)**, `transactions.invoice` tidak unik → perlu keputusan penambahan constraint. Generator nomor harus di-scope per business. | Tinggi: race/sequencing nomor per business + tabel non-unik. | FIN-01; desain generator |
| **UNIQUE-11** (Q4.11) | **Prinsip**: terapkan per-business untuk identifier milik business; **jangan ubah kandidat tambahan tanpa evidence & approval terpisah.** | — | Belum ada perubahan. Kandidat spesifik (mis. `transactions.client_uuid` = open-decisions **Q11**, `customer_campaigns.context_key`) menunggu evidence + approval terpisah. | Sedang | evidence + approval terpisah |

**Catatan UNIQUE (FAKTA):** pada MySQL, composite `(business_id, X)` menganggap `business_id IS NULL` sebagai distinct → **backfill non-null wajib mendahului** composite index.

### 13.3 Settings (SETTINGS) — Q5

| ID | Keputusan owner | Konsekuensi teknis | Migration / data impact | Risiko | Dependency |
|---|---|---|---|---|---|
| **SETTINGS-01** (Q5.1) | **B — Global + Business + Outlet** (hierarki `global → business → outlet` dengan inheritance/override). | Perlu precedence resmi: outlet > business > global. | Tambah `business_id` di `settings` + composite unik (mis. `(key, business_id, outlet_id)`) + backfill. | Tinggi: bug precedence → bocor/override salah. | FIN-01, SETTINGS-02..07 |
| **SETTINGS-02** (Q5.2) | **B — Per-business** (`store_npwp`) | Tulis/baca business-scoped. | Backfill baris global `store_npwp` ke business. | Tinggi (identitas legal). | SETTINGS-01, FIN-01 |
| **SETTINGS-03** (Q5.3) | **B — Per-business** (`store_nib`) | Tulis/baca business-scoped. | Backfill. | Tinggi | SETTINGS-01, FIN-01 |
| **SETTINGS-04** (Q5.4) | **D — Business default + outlet override** (`tax_default_rate`) | Resolusi: outlet → business → (default). | Butuh `business_id`; backfill. Perbaiki inkonsistensi tulis-global vs baca-outlet. | Tinggi (pajak) | SETTINGS-01, FIN-01 |
| **SETTINGS-05** (Q5.5) | **D — Business default + outlet override** (`discount_approval_*`) | Resolusi berjenjang. | Backfill + `business_id`. | Sedang | SETTINGS-01, FIN-01 |
| **SETTINGS-06** (Q5.6) | **D — Business default + outlet override** (`loyalty_*`) | Resolusi berjenjang; `setMany` harus business-scoped. | Backfill + `business_id`. | Sedang | SETTINGS-01, FIN-01 |
| **SETTINGS-07** (Q5.7) | **Prinsip**: klasifikasi key lain berdasarkan fungsi aktual; platform-global hanya bila benar-benar platform-level; business-scoped untuk konfigurasi tenant; outlet override hanya bila perlu secara bisnis. | `app_setup_completed`, `setup_warehouse_id` = platform-global; store profile & printer/wa/target = outlet (saat ini); key tenant lain = business. | Detail per-key ditetapkan saat desain implementasi. | Rendah (klasifikasi) | SETTINGS-01 |

### 13.4 Financial ownership & records (FIN) — Q7 / Q9 / Q15

| ID | Keputusan owner | Konsekuensi teknis | Migration / data impact | Risiko | Dependency |
|---|---|---|---|---|---|
| **FIN-01** (Q7.1) | **C — Hybrid**: backfill otomatis hanya bila ownership **100% terbukti** dari parent tervalidasi; sisanya manual. | Definisi "100% confidence" harus eksakt (mis. `payable→purchase_order→warehouse→business_id` non-null & konsisten). | Perlu `business_id` di `payables`/`receivables` (saat ini ownership hanya via parent) + perluasan `business:backfill`. | **Kritis**: ownership salah bersifat permanen. | Q5? tidak; FIN-02/03/04/05 |
| **FIN-02** (Q7.2) | **A — Manual assignment oleh administrator** (record tanpa parent). | Ada mekanisme assignment admin untuk manual payable/receivable. | `business_id` nullable + UI/workflow + audit. | Tinggi | FIN-01, AUTH-* |
| **FIN-03** (Q7.3) | **C — Manual reconciliation** (parent dangling/deleted). | Record dangling menunggu rekonsiliasi manual. | Tidak ada auto-ownership; audit. | Sedang | FIN-01, FIN-05 |
| **FIN-04** (Q9.1) | **A — Wajib business context**: manual payable/receivable baru harus dibuat dalam active business context; tanpa context → tolak. Mutasi mengikuti authorization setelah **AUTH-01/02/03**; **jangan** menambah permission baru berdasarkan keputusan ini. | Create manual record butuh context; tolak tanpa context. | Perubahan kode (task terpisah) + `business_id`. | Tinggi | FIN-01, AUTH-02/AUTH-03 |
| **FIN-05** (Q15.1) | **A — Quarantine**: record unresolved tetap tersimpan untuk audit, tidak muncul di tenant normal, tidak dapat dimutasi, hanya via reconciliation/admin workflow. | Perlu status quarantine + filter baca + blokir mutasi. | Perlu penanda status/scope + audit. | Sedang | FIN-01, FIN-02/03, FIN-04 |

### 13.5 Scheduler (SCHED) — S1 / S2

| ID | Keputusan owner | Konsekuensi teknis | Migration / data impact | Risiko | Dependency |
|---|---|---|---|---|---|
| **SCHED-01** (S1) | **C — Hybrid**: operasi ringan boleh iterate active business secara terkontrol; operasi berat queue per business dengan explicit business context. | Perlu kerangka iterasi per-business (set context → execute → clear) + jalur queue untuk job berat. | Tidak ada schema; perlu desain job/queue. | Sedang–Tinggi | SCHED-02, AUTH-*, **Q8** (lihat §13.6) |
| **SCHED-02** (S2) | Scheduler **boleh** memproses semua active business otomatis, dengan **business context eksplisit per execution** dan **context wajib di-clear** setelah selesai. Tidak perlu business-level enablement kecuali repository menunjukkan kebutuhan bisnis yang sudah ada. | Setiap run: enumerate active businesses, set context, jalankan, clear. | Tidak ada. | Sedang: context leak bila clear gagal. | SCHED-01 |

### 13.6 Konflik / risiko yang ditandai (WAJIB dibaca sebelum implementasi)

1. **Konsistensi AUTH:** AUTH-01 (C) + AUTH-02 (A) + AUTH-03 (C) **konsisten**. **Residual risk** yang diterima secara implisit: karena `teams=false`, permission Spatie tetap global, sehingga user yang punya permission sensitif di Business A dapat memakainya di Business B tempat ia member. Ini **belum tertutup** oleh keputusan ini dan bergantung pada desain implementasi AUTH-02/AUTH-03 (bukan kontradiksi, tetapi risiko yang harus diselesaikan).
2. **SCHED-01 vs Q8 (open-decisions):** opsi "queue per business" berpotensi **bertentangan** dengan batasan open-decisions **Q8** (shared hosting tanpa queue worker/Redis). Perlu klarifikasi pada saat desain scheduler; tidak diselesaikan di sini.
3. **UNIQUE-08 / UNIQUE-09:** `customer_vouchers` dan `customer_segments` **belum punya `business_id`** → B/C hanya dapat diimplementasikan setelah **approval schema terpisah**.
4. **UNIQUE-10:** `payables.document_number` (index, bukan unik) dan `transactions.invoice` (tidak unik) → penetapan "per-business unique" untuk keduanya memerlukan keputusan penambahan constraint terpisah.
5. **UNIQUE-11:** keputusan hanya berupa **prinsip**; kandidat spesifik (mis. `transactions.client_uuid` = open-decisions **Q11**, `customer_campaigns.context_key`) **menunggu evidence + approval terpisah**.

### 13.7 Dampak test / acceptance (belum dijalankan)

- Test isolasi yang ada (`tests/Feature/Business/*`, `tests/Feature/Tenancy/*`) tetap berlaku; keputusan ini **tidak** mengubah kode.
- Task implementasi terpisah wajib menambah test untuk: composite uniqueness per business (UNIQUE-01..10), resolusi settings berjenjang (SETTINGS-01..07), hybrid backfill confidence (FIN-01), manual assignment (FIN-02), quarantine (FIN-05), dan iterasi/context scheduler (SCHED-01/02).

### 13.8 Explicit non-authorizations

- **Bukan** izin migration / schema change / backfill / data change / feature-flag activation / deployment.
- Approval satu keputusan **tidak** memberikan approval untuk keputusan lain.
- Tidak ada kode aplikasi yang diubah pada bagian ini.

### 13.9 Peta penomoran (GOV-01 / X1.1 = C)

| ID stabil | ID lama (register/task) | ID lama (open-decisions *berbeda makna*) |
|---|---|---|
| AUTH-01 | Q2 | Q2 |
| AUTH-02 | Q3 | Q3 |
| AUTH-03 | Q14 | Q14 |
| UNIQUE-01..11 | Q4.1..Q4.11 | Q4 (≠, arti: master data isolation) |
| SETTINGS-01..07 | Q5.1..Q5.7 | Q5 |
| FIN-01/02/03 | Q7.1/Q7.2/Q7.3 | Q7 (≠, arti: outlet = one business) |
| FIN-04 | Q9.1 | Q9 (≠, arti: data tanpa ownership) |
| FIN-05 | Q15.1 | Q15 (≠, arti: warehouses.outlet_id NULL) |
| SCHED-01/02 | S1/S2 | Q8 (terkait) |
| GOV-01 | X1.1 | — |

> **GOV-01**: keputusan owner = **C — gunakan ID stabil** (`AUTH-*`, `FIN-*`, `UNIQUE-*`, `SETTINGS-*`, `SCHED-*`, `GOV-*`) dengan cross-reference ke ID lama. Cross-reference ke `multi-business-open-decisions.md` dipertahankan melalui tabel di atas.

### 13.10 Phase 2A — cross-reference implementasi FIN-01 (tanpa mengubah keputusan)

- **Tanggal:** 2026-10-11. **Tidak ada keputusan owner yang diubah.**
- Artefak baru (read-only, tanpa migration/schema/data/flag/deploy):
  - `app/Support/Tenancy/FinancialOwnershipResolver.php` — klasifikasi kepemilikan payables/receivables (read-only, 100% terbukti).
  - `app/Support/Tenancy/FinancialOwnershipResult.php` — DTO klasifikasi + evidence + reason code.
  - `app/Console/Commands/FinancialOwnershipReportCommand.php` — `business:financial-ownership-report` (tanpa mode tulis).
  - `tests/Feature/Business/FinancialOwnershipDryRunTest.php` — 19 test (56 assertion).
  - `docs/architecture/financial-ownership-resolution-design.md`, `docs/architecture/financial-ownership-dry-run-report.md`.
- **Temuan implementasi (FAKTA):**
  - Rantai kepemilikan terverifikasi: `payables → purchase_orders → warehouses → (business_id | outlets.business_id)`; `receivables → transactions → warehouses → …`. `payables`/`receivables` **tidak** punya `business_id`; `purchase_orders`/`transactions`/`goods_receivings`/`supplier_returns` juga tidak.
  - `business:backfill --apply` tidak menyentuh tabel finansial; pengesahan ganda tetap berlaku.
  - `ALREADY_RESOLVED` belum dapat tercapai pada fase ini (tidak ada kolom `business_id`).
  - Koreksi audit: query §2.5 lama merujuk `purchase_orders.business_id` yang **tidak ada**; sudah diperbaiki menjadi konflik `warehouses.business_id` vs `outlets.business_id`, plus query resolusi node di `multi-business-readonly-audit-queries.md`.
- **Belum ada** implementasi write/backfill untuk `payables`/`receivables`. Diperlukan approval terpisah untuk schema `business_id` + mode apply.

### 13.11 Tautan proposal desain lanjutan (FIN-01/02/03/05) — tanpa mengubah keputusan

- **Tanggal:** 2026-10-11. **Tidak ada keputusan owner yang diubah.**
- Dokumen: `docs/architecture/financial-ownership-schema-and-operations-design.md`.
- Isi: rancangan kolom nullable `business_id`, strategi FK/index, representasi karantina (FIN-05), alur penetapan manual (FIN-02), rekonsiliasi (FIN-03), rencana audit read-only produksi, kontrak apply-mode dengan dual confirmation, serta rencana migrasi/validasi/rollback + kriteria penerimaan.
- **Status:** design/documentation only. **Belum ada** migration, perubahan schema/data, permission/workflow, aktivasi flag, atau deployment. Setiap butir **[APPROVAL]** memerlukan persetujuan owner terpisah.
- Rekomendasi tindakan berikutnya tanpa approval: jalankan `business:financial-ownership-report` pada read replica untuk mengganti total produksi yang masih **UNKNOWN** dengan angka nyata.

### 13.12 Persiapan audit data produksi + koreksi desain FIN-01 — tanpa mengubah keputusan

- **Tanggal:** 2026-10-11. **Tidak ada keputusan owner yang diubah.** Dokumentasi + inspeksi non-mutasi saja; tidak ada koneksi ke produksi/replica, tidak ada query DB.
- **Total produksi tetap UNKNOWN.** Tidak ada read replica/restored copy yang tersedia; angka fixture **bukan** angka produksi.
- **Dokumen baru:** `docs/architecture/financial-ownership-audit-runbook.md` — verifikasi kompatibilitas engine/schema, runbook eksekusi pada read replica/restored copy (identifikasi environment, safeguard read-only, capture, rekonsiliasi hitungan, retensi bukti), dan pemeriksaan pra-backfill (backup/restore, concurrent-write, audit-log, batch-failure, idempotensi, perbandingan sebelum/sesudah, rollback rehearsal).
- **Koreksi desain (tanpa ubah keputusan):**
  1. State machine karantina di `financial-ownership-schema-and-operations-design.md` §2.1 diperbaiki: tepat **enam** status resolusi; karantina adalah **predikat turunan** (`ownership_status != 'resolved'`), **bukan** status ketujuh, dan **tidak terminal** (FIN-05 tetap membolehkan rekonsiliasi). Ditambah pemetaan eksplisit klasifikasi/resolver → status.
  2. SQL audit `multi-business-readonly-audit-queries.md` diperbaiki: §2.4 kini men-join `outlets` (menutup kasus outlet unowned/missing), dan §3 memakai `SUBSTR(...)` + mengutip `key` agar portabel MySQL/SQLite.
- **Register cacat (D1–D10):** lihat §10 dokumen desain. Yang butuh kode/approval terpisah: status record baru (D3), propagasi karantina ke payment (D5), field provenance (D7), JSON+`--list` (D8), teks limitasi statis (D9), precondition audit `run_id` (D6). Pilihan owner eksplisit: `restrictOnDelete` vs `nullOnDelete` (D4).
- **Status:** design/documentation only. **Belum ada** migration, perubahan schema/data, permission/workflow, aktivasi flag, deployment, atau apply-mode. `multi_business.enabled` tetap `false`.
- **Approval berikutnya yang eksplisit:** (a) izin operasional untuk menjalankan runbook pada read replica/restored copy yang diotorisasi guna mendapatkan angka nyata; (b) setelah itu, approval schema `business_id` + `ownership_status` (FIN-01/05).

### 13.13 Implementasi FIN-01/02/03/05 (schema + apply-mode + karantina + FIN-04) — mengikuti rekomendasi desain

- **Tanggal:** 2026-10-11. **Mengikuti rekomendasi desain FIN-01/02/03/05** (butir FIN pada §13.4 + dokumen `financial-ownership-schema-and-operations-design.md`). `multi_business.enabled` tetap `false`; **tidak ada** migrasi/akses ke data produksi, **tidak ada** mutasi apply-mode ke DB nyata, **tidak ada** deployment. Kode siap ditinjau owner.
- **Keputusan D4 (FK):** diimplementasikan **`restrictOnDelete`** — bisnis dirujuk oleh baris finansial tidak dapat di-hard-delete (bisnis diarsipkan, bukan dihapus). Tercatat untuk veto owner sebelum deploy.
- **Migration (3, semua additive):**
  - `2026_10_11_100001` — `payables.business_id` (nullable FK `businesses` RESTRICT; index `[business_id,status]`, `[business_id,due_date]`, `[business_id,supplier_id]`).
  - `2026_10_11_100002` — `receivables.business_id` (sama; index `[business_id,status]`, `[business_id,due_date]`, `[business_id,customer_id]`).
  - `2026_10_11_100003` — `ownership_status` (string 20, default `'unassigned'`, index), `ownership_evaluated_at` (nullable), `ownership_note` (text), index `[business_id,ownership_status]`.
  - Urutan jaminan: `evaluateTable` menulis **hanya status**; `business_id` hanya ditulis oleh `applyBackfill` (validated, per-baris `lockForUpdate` + audit `run_id`) atau `assign` manual (FIN-02).
- **Mesin baru:**
  - `app/Services/FinancialOwnershipService.php` — pemetaan status 6-state, `businessIdForWarehouse`/`stampFromWarehouse` (FIN-04), `evaluateTable`, `applyBackfill` (idempoten; konflik persisted-vs-chain → `ambiguous`), `assign` (fail closed: bisnis non-aktif, rekaman hilang, reassign tanpa reason, konflik rantai tanpa override).
  - `app/Support/Tenancy/FinancialOwnershipScope.php` — global scope model Payable/Receivable saat flag ON: baris `resolved`+milik konteks **atau** transisi `unassigned` terlihat; **semua status dievaluasi non-resolved dikarantina** (bahkan saat rantai membuktikan bisnis); tanpa konteks tidak ada kebocoran record resolved bisnis lain (perilaku setara fase 4E).
  - `app/Support/Tenancy/TenantOwnership.php` — branch VIA_PARENT kini mementingkan `business_id` persisted saat `resolved`; karantina menutup mutasi (D5); fallback rantai tetap untuk `unassigned` (26 test `AuthorizationBoundaryIsolationTest` tetap hijau tanpa perubahan).
  - `app/Console/Commands/FinancialOwnershipBackfillCommand.php` — `business:financial-ownership-backfill` (dry-run default read-only, `--apply` wajib `--confirm-financial-ownership`, `--table/--chunk/--limit` staged, `--report` JSON ber-provenance, exit non-zero saat AMBIGUOUS/conflict).
  - `app/Console/Commands/FinancialOwnershipAssignCommand.php` — `business:financial-ownership-assign` (FIN-02, `--note/--reason/--override-conflict/--actor`).
  - `FinancialOwnershipReportCommand.php` — cacat **D7** (provenance driver/database/env/version/args), **D8** (`--format=json` + `--list` menghasilkan array records), **D9** (limitasi ALREADY_RESOLVED kini berkondisi pada ada/tidaknya kolom).
- **FIN-04 (jalur pembuatan):** `CheckoutService` (receivable pay-later) distempel via `stampFromWarehouse`; `PayableController::store` wajib konteks aktif saat flag ON (403 oleh `EnsureActiveBusiness` di jalur web; abort 422 sebagai defense-in-depth) dan menetapkan `business_id`+`resolved`.
- **Permission:** `financial-ownership-reconcile`, `financial-ownership-assign`, `financial-ownership-quarantine` (super-admin only; dikeluarkan dari manager di `RoleSeeder`).
- **Test:** 3 file baru (Schema 4, Backfill 16, Quarantine 10) + update 2 assert DryRun; suite penuh **667 passed (2791 assertions)** — naik dari 637 baseline. `vendor/bin/pint` bersih.
- **Deferred (bukan bagian slice ini):** UNIQUE tenant-scoped (butuh backfill non-null produksi dulu), SETTINGS business_id/precedence, SCHED/job tenancy (F12), AUTH-enforcement penuh (residual teams=false), Fase 8 UI, runbook produksi (butuh read replica + approval §13.12).
- **Status:** kode siap review. Flag tetap `false`; DB lokal/tests tidak bermutasi data produksi. Operator harus menjalankan evaluasi + backfill + assign sebelum mengaktifkan flag.

### 13.14 Implementasi slice lanjutan (SETTINGS, UNIQUE, SCHED, AUTH, Phase-8-lite UI) — 2026-10-12

- **Tanggal:** 2026-10-12. **Mengikuti keputusan owner §13.1/§13.3/§13.4/§13.5** (AUTH-01/02/03, UNIQUE-01/02/03/05/06/07, SETTINGS-01..07, SCHED-01/02). `multi_business.enabled` tetap `false`; `phpunit.xml` mem-*pin* flag `false` agar suite hermetis. **Tidak ada** mutasi data produksi; migration additive + guarded (inert saat flag off).
- **SETTINGS (Q5 / ADR-04 opsi C):**
  - `settings.business_id` nullable FK; resolusi berjenjang `outlet → business → global`; `PLATFORM_KEYS` (`app_setup_completed`, `setup_warehouse_id`) tetap global.
  - Tulisan tenant tanpa konteks bisnis **fail closed** (`TenantSettingScopeException`); baca mewarisi baris global sebagai base layer.
  - Migration `2026_10_12_000001_add_business_id_to_settings_table.php`; command `business:settings-backfill` (dry-run default) untuk baris tenant legacy.
- **UNIQUE (Q4, opsi B — per business) untuk UNIQUE-01/02/03/05/06/07:**
  - Swap unique global → composite `(business_id, kolom)`: `products.barcode`/`sku`, `price_lists.slug`, `warehouses.code`, `outlets.code`, `customers.member_code`.
  - Migration `2026_10_12_000002_add_business_scoped_unique_indexes.php` — **data-guarded**: swap hanya bila kolom `business_id` tidak ada `NULL`, sehingga instalasi single-tenant legacy mempertahankan index global.
  - `TenantUnique` di-wire ke validasi web + API (products, warehouses, price_lists, outlets).
  - UNIQUE-04 (`units.code`) tetap global; UNIQUE-08/09 (butuh kolom `business_id`) dan UNIQUE-10/11 tetap deferred (§13.6).
- **SCHED (SCHED-01/02):**
  - `App\Support\Tenancy\BusinessTaskRunner` — enumerate active business, set context → execute → **selalu** clear (`finally`); operasi ringan inline (tanpa queue, sesuai batasan Q8 shared hosting).
  - Di-wire ke `reorder:generate`, `crm:sync-segments`, `crm:generate-reminders`; `customer_campaigns.context_key` di-namespace per business agar tidak bentrok lintas business (schema `business_id` campaigns/segments tetap deferred §13.6). Flag off = satu pass legacy tanpa konteks (tidak berubah).
- **AUTH (AUTH-01/02/03) — penutupan residual `teams=false`:**
  - `App\Support\Tenancy\BusinessRoleCapabilities` — capability per **business membership role**: `owner` = semua permission kecuali administrasi platform lintas-business (`users-*`, `roles-*`, `permissions-*`, `financial-ownership-*`); `manager` = role Spatie `manager`; `staff` = role Spatie `cashier`. Memakai definisi role Spatie live (tanpa permission/role baru).
  - `App\Http\Middleware\EnforceBusinessRoleCapability` (alias `business.role`) — menegakkan capability rute (`permission:` dashboard, `abilities:` API) terhadap role membership aktif; super-admin memakai jalur platform (bypass); flag off no-op.
  - Di-wire ke grup dashboard serta grup master-data & `pos` API.
- **Phase-8-lite UI:**
  - Business switcher (`BusinessContextController::switch`, `BusinessSwitcher.jsx`, shared props `auth.currentBusiness`/`auth.businesses`) sudah ada. Residual yang ditutup: `HandleInertiaRequests` membagikan `auth.permissions` **per business** (role membership aktif) saat flag ON, agar gate UI sama dengan enforcement server. Super-admin & flag off memakai peta global legacy (tidak berubah).
- **Test:** baru — `BusinessScopedUniquenessTest` (5), `SettingTenantScopeTest`, `StoreSettingsIsolationTest`, `BusinessSettingsBackfillTest`, `SchedulerTenantContextTest` (4 → 5 assertions), `AuthzBusinessRoleEnforcementTest` (5); plus perluasan `BusinessTenancyUiDemoTest` (3) dan penyesuaian fixture isolasi ke membership `manager`.
- **Masih deferred (§13.6/§13.12/§13.13):** UNIQUE-10 (nomor dokumen + generator), UNIQUE-11 (kandidat menunggu evidence), langkah swap index manual + runbook produksi & backfill non-null (butuh read replica + approval), Fase 8 penuh (onboarding/rollout, keputusan Q6), aktivasi flag & deployment. UNIQUE-08/09 + ownership `customer_campaigns`/`customer_segments`/`customer_vouchers` kini SELESAI (§13.17).
- **Batas keselamatan:** tidak ada migrasi/backfill pada data nyata, tidak ada aktivasi flag, tidak ada deployment. Semua perubahan inert saat `multi_business.enabled=false`.

### 13.15 Membership provisioning + demo readiness — 2026-10-12

- **Gap yang ditutup:** `business:backfill` hanya menstempel `business_id` pada master data/outlet/warehouse. **Tidak ada** apa pun yang membuat baris `business_user`, sehingga dengan flag ON seorang user non-super tidak punya konteks bisnis tervalidasi dan ditolak (403) — fitur tidak bisa dipakai walau kode sudah lolos test.
- **Baru:**
  - `App\Support\Tenancy\BusinessMembershipProvisioner` — menurunkan membership dari **akses outlet** user; mapping role platform → role business: `super-admin` = tanpa membership (jalur platform AUTH-01), `owner` → `owner`, `manager` → `manager`, `cashier` → `staff`, role lain **di-skip** (fail closed, harus assign manual). Idempotent (membership yang sudah ada tidak ditimpa). Mengisi `default_outlet_id` dari outlet user di business tersebut.
  - `business:membership-backfill` — dry-run default; `--apply --confirm-membership-backfill` untuk menulis; `--business=ID` untuk menargetkan satu business.
  - `DemoBusinessSeeder` (dipanggil `DemoSeeder`) — membuat business demo bila belum ada, memetakan outlet/warehouse/master data yang masih `NULL` ke business tersebut, lalu provisioning membership. `seed:demo` kini menghasilkan dataset yang **siap flag ON**.
- **Test:** `BusinessMembershipBackfillCommandTest` (8), `DemoFlagOnAccessTest` (3 — bukti end-to-end: manager demo lolos route manager, kasir demo di-scope ke `staff`, super-admin jalur platform), perluasan `SeedDemoCommandTest` (assert business scoping + membership + idempotensi).
- **Catatan onboarding:** sebelumnya diblokir ke Q6. Ditutup via bootstrap first-install di §13.16 (selaras rekomendasi Q6 fase awal: admin platform membuat business, tanpa menambah UI).
- **Batas keselamatan:** command default dry-run; tidak ada data produksi yang disentuh; flag tetap `false`.

### 13.16 Onboarding bootstrap (first-install) — 2026-10-12

- **Gap yang ditutup:** dengan flag ON, install baru gagal total — setup wizard **tidak** membuat business, sementara resolver context bersifat fail-closed (`1 = 0` / 403) saat belum ada business aktif. Bahkan super-admin pun tidak bisa membuka dashboard, dan `Setting::set` untuk key tenant melempar `TenantSettingScopeException`; read `Warehouse`/`Category` terglobal-scope sehingga wizard setup bisa 500.
- **Baru:**
  - `App\\Support\\Tenancy\\BusinessDataScoper` — satu definisi tabel business-owned (outlet/warehouse + master data), `assignUnscoped()` untuk menstempel baris `NULL`.
  - `App\\Support\\Tenancy\\BusinessBootstrap` — buat business default (idempotent) + scope; dipakai setup dan demo.
  - `SetupController` — saat flag ON: bootstrap business (nama dari `store_name`), set `BusinessContext` aktif sehingga setting toko (SETTINGS-07) ditulis ke baris business; read/update warehouse memakai `withoutGlobalScope('business')` (setup berjalan sebelum konteks ada). Flag OFF: perilaku lama identik, tidak ada business.
- **Dampak data:** install baru dengan flag ON kini punya 1 business aktif yang memiliki outlet/warehouse/master-data hasil wizard; super-admin tetap jalur platform (AUTH-01, tanpa membership). Install existing tidak tersentuh — tetap lewat `business:backfill --create-default --apply` + `business:membership-backfill --apply`.
- **Test:** `SetupWizardTest` +3 (`setup bootstraps default business when tenancy enabled`, `setup creates no business when tenancy disabled`, `index returns primary warehouse when tenancy enabled`). Total 20 setup test.
- **Keputusan Q6 yang masih tersisa:** UI/rollout pembuatan business **tambahan** dan self-service owner signup — bukan bagian dari bootstrap ini (fase lanjut dengan kontrol abuse, menunggu keputusan Anda).

### 13.17 UNIQUE-08/09 + CRM ownership — 2026-10-12

- **Dibuka kembali:** §13.6 menandai UNIQUE-08/09 "deferred, butuh approval schema terpisah". Karena owner memutuskan menuntaskan residual UNIQUE, kolom `business_id` untuk blok CRM sekarang diimplementasikan (keputusan = approval schema tersebut).
- **Baru:**
  - Migration `2026_10_12_000003_add_business_scope_to_crm_blocks.php`:
    - `customer_vouchers.business_id` (nullable FK) + swap `code` unique global → composite `(business_id, code)` (UNIQUE-08).
    - `customer_segments.business_id` (nullable FK) + swap `slug` unique global → composite `(business_id, slug)` (UNIQUE-09).
    - `customer_campaigns.business_id` (nullable FK) — **hanya ownership**; `context_key` tetap unique global (UNIQUE-11: kandidat menunggu evidence/approval terpisah).
    - Swap **data-guarded** (sama seperti `2026_10_12_000002`): hanya dieksekusi saat nol baris `NULL`; instalasi legacy menyimpan index global sampai backfill + langkah swap manual dalam runbook produksi (konsekuensi terakui §"Catatan UNIQUE").
  - Model `CustomerSegment`, `CustomerCampaign`, `CustomerVoucher`: `BelongsToBusiness` + `business_id` fillable → read/`create` tenant-scoped saat flag ON + konteks aktif; inert saat flag off.
  - `CustomerVoucherController`: rule `Rule::unique(...)` → `TenantUnique::for(...)` agar validasi tidak membocorkan kode voucher business lain (konsisten ADR-03).
  - `BusinessBackfillCommand` + `BusinessDataScoper`: tabel `customer_segments`/`customer_campaigns`/`customer_vouchers` masuk daftar business-owned; command kini melewati tabel tanpa kolom `business_id` (aman di DB lama).
  - **Verifikasi kepemilikan** (`TenantOwnership`) **tidak diubah**: blok CRM tetap diverifikasi via `outlet_id` (sesuai ownership matrix), sementara `business_id` baru dipakai untuk scope read/create (`BelongsToBusiness`) dan index unique — baris orphan (outlet null) tetap fail closed.
- **Test:** `BusinessScopedUniquenessTest` +5 (slug segmen & kode voucher: boleh lintas business, tolak duplikat dalam business — level DB; campaign mewarisi `business_id` dari konteks). `BusinessBackfillCommandTest` +1 (backfill men-stempel segmen & voucher). Fixture isolation test diperbarui: campaign diberi `business_id` eksplisit (perilaku produksi); verifikasi post-command memakai `withoutGlobalScope('business')` (konteks memang sudah di-clear).
- **Masih deferred:** UNIQUE-10 (nomor dokumen PO/GR/SR/ST + generator nomor per business), UNIQUE-11 (kandidat lain menunggu evidence, mis. `transactions.client_uuid`), dan kandidat penambahan constraint non-unik (`payables.document_number`, `transactions.invoice`).

### 13.18 Runbook backfill produksi — 2026-10-12

- **Baru:** `docs/architecture/multi-business-backfill-production-runbook.md` — runbook bertahap untuk eksekusi produksi: Fase 0 audit read-only (query NULL per tabel + settings tenant + download perintah audit) → Fase 1 deploy & migrasi (additive/guarded) → Fase 2 planning dry-run (tiga command) → Fase 3 gate approval eksplisit (owner + DBA) → Fase 4 apply bertahap (backfill → settings → memberships, dual confirmation) → Fase 5 swap index manual ke composite (DDL MySQL per tabel, `units.code` dikecualikan) → Fase 6 verifikasi (nol NULL, duplikat per scope, smoke staging flag ON) → Fase 7 (deferred) aktivasi flag. Termasuk tabel rollback per fase + checklist approval.
- **Status:** **DRAFT, belum dieksekusi.** Tidak ada data yang diubah. Menunggu (a) read replica/restored copy terotorisasi, (b) approval eksplisit owner + DBA. Dokumen ini mengunci referensi "runbook produksi" pada §13.15/§13.17.
- **Batas:** flag tetap `false`; demo seeder dilarang di produksi; UNIQUE-10/11 dan UI Q6 di luar lingkup.

### 13.19 Q6 (keputusan A) — pembuatan business oleh platform admin — 2026-10-12

- **Keputusan owner:** **A. Admin platform only.** Business tambahan hanya dibuat oleh super-admin (jalur platform eksplisit, AUTH-01). Self-service owner signup tetap deferred (fase lanjut dengan kontrol abuse); bootstrap first-install (§13.16) tidak berubah.
- **Baru:**
  - Middleware `platform.admin` (`EnsurePlatformAdmin`): gate berbasis peran `super-admin` — **tanpa permission Spatie baru** sehingga kendala FIN-04 ("no new permissions") tetap berlaku. Independen dari `business.required` agar manajemen business bisa berjalan tanpa konteks aktif (mis. menormalkan instalasi yang seluruh business-nya tersuspend).
  - `BusinessProvisioner` (`app/Support/Tenancy`): provisioning tenant baru — Business aktif + slug unik, skeleton PUSAT (outlet pusat non-sales + gudang type `main`) yang di-stempel `business_id` eksplisit, membership owner opsional (role `owner`, aktif, default outlet PUSAT), audit `business.created`. **Tidak** memakai `BusinessDataScoper::assignUnscoped` (itu akan mencuri baris legacy yang belum di-backfill); konteks aktif dialihkan sementara ke business baru saat flag ON agar hook `BelongsToBusiness` menulis id yang sama.
  - `BusinessAdminController` (Apps) + routes `dashboard/businesses*` di luar grup `business.required`: index/create/store/update (rename)/updateStatus, semua write memakai `step_up`. **Tidak ada destroy** — suspend adalah operasi lifecycle; menangguhkan satu-satunya business aktif diblokir (422) agar tenancy tidak pernah tanpa tenant aktif (fail-closed).
  - UI: `Pages/Dashboard/Businesses/{Index,Create}.jsx` + item sidebar "Business" di section Manajemen User — tampil hanya saat `multi_business.enabled` **dan** user super-admin (nav global dikunci: prop `multiBusinessEnabled` baru di `HandleInertiaRequests`, ditambahkan ke `auth.super`).
  - Validasi owner: harus user non-super-admin yang ada (super-admin tidak bisa jadi owner — konsisten `BusinessMembershipProvisioner` skip super-admin).
- **Test:** `tests/Feature/Business/BusinessAdminTest.php` +13 (65 assertions): boundary akses (guest→login, manager/cashier→403), list, store tanpa/dengan owner, owner super-admin/tiada → ditolak, skeleton PUSAT milik masing-masing business (dua business → dua PUSAT tanpa clash composite unique), rename + audit, suspend/reactivate, blokir suspend business terakhir, `step_up` wajib.
- **Batas:** flag tetap `false`; UI ini aktif sejak sekarang tapi item sidebar tersembunyi sampai flag ON. UNIQUE-10/11, aktivasi flag, dan self-service owner signup tetap deferred. PR disiapkan ke `development`.
