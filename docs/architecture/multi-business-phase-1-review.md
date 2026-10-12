# Multi-Business — Phase 1 (Build 1.1) Review

- **Status:** LAPORAN REVIEW. Tidak ada perubahan kode/database/deployment dilakukan pada tugas ini.
- **Tanggal:** 2026-10-10
- **Metode:** inspeksi kode aktual (bukan hanya laporan Build 1) + menjalankan test suite penuh.
- **Dokumen dasar:** `multi-business-tenant-audit.md`, `multi-business-backend-implementation-plan.md`, `multi-business-data-ownership-matrix.md`, `multi-business-security-test-plan.md`, `multi-business-open-decisions.md`, `multi-business-phase-1-implementation-notes.md`.
- **Disclaimer:** Tidak ada migration/seeder dijalankan terhadap DB apa pun selain SQLite in-memory milik test suite (`phpunit.xml`).

---

## 1. Ringkasan Hasil Review

Build 1 **secara teknis sehat dan aman** untuk apa yang diklaim di `multi-business-phase-1-implementation-notes.md`:

- Skema additive (`businesses`, `business_user`, `outlets.business_id`) konsisten, FK/unique/index benar, urutan migrasi benar.
- Relasi `Business`/`BusinessUser`/`User`/`Outlet` konsisten.
- Tenant context terverifikasi server (session + membership), tidak bocor antar-request, dan tidak dapat diakses tanpa membership valid (non-super-admin).
- Kebijakan super-admin sesuai rekomendasi: tetap global, tidak otomatis owner semua business.
- Perbaikan keamanan `OutletAccessService::warehousesFor()` dan `UserRequest` efektif dan tertutup test.
- Full test suite: **474 passed, 0 failed** (2111 assertions, ~125s).

**Namun**, Build 1 **menyimpang dari cakupan "Fase 1" di implementation plan** dan **naik ke sebagian Fase 2**, serta **belum memenuhi kriteria Fase 1 plan** ("business default dibuat", "belum ada perubahan perilaku"). Ini perlu dicatat eksplisit, bukan dianggap bug.

Kesimpulan: fondasi **cukup untuk Build 2 secara struktur**, tetapi **belum boleh diaktifkan pada route produksi** dan **belum aman multi-tenant penuh**. Keputusan terbuka (terutama Q2/Q3/Q7/Q9) masih menghambat backfill & isolasi.

---

## 2. Temuan Berdasarkan Tingkat Keparahan

### 2.1 TINGGI (harus dikelola sebelum/di Build 2)

**H1 — Deviasi cakupan Fase 1 vs plan (bukan bug, tapi risiko rework/gate).**
- Plan Fase 1 (`multi-business-backend-implementation-plan.md:427-435`) menargetkan: tabel `businesses`, `business_user`, **business default**, seeder, dan kriteria "**belum ada perubahan perilaku**".
- Build 1 mengerjakan skema + membership **tanpa** business default/seeder, dan **mengubah perilaku** `warehousesFor()`.
- Bukti: tidak ada file seeder business; `docs/architecture/multi-business-phase-1-implementation-notes.md:39-46` menyatakan backfill/business default ditunda.
- Dampak: Fase 3 (backfill) belum punya anchor "business default"; gate Fase 1 plan belum tuntas.

**H2 — Build 1 melampaui Fase 1 (masuk komponen Fase 2) secara sengaja.**
- `BusinessContext`, `BusinessMembershipResolver`, `ResolveBusinessContext`, `EnsureActiveBusiness` adalah komponen plan Fase 2 (`...implementation-plan.md:437-445`), bukan Fase 1.
- Ini bukan cacat, tetapi berarti urutan fase plan tidak diikuti; rollout harus mengikuti catatan ini saat menyusun Build 3+.

**H3 — Fondasi belum multi-tenant aman (diketahui & disengaja).**
- Tidak ada isolasi master data/transaksi/stok; tidak ada validasi FK lintas business; middleware tidak terpasang.
- Bukti: `multi-business-phase-1-implementation-notes.md:48-62`. **Jangan aktifkan multi-business pada route produksi.**

### 2.2 SEDANG

**M1 — Keputusan Q2/Q3/Q7/Q9 (dan Q14) belum disetujui tetapi sudah dipakai kode.**
- `business_user.role`, status, dan semantik super-admin mengikuti REKOMENDASI Opsi A (`multi-business-backend-implementation-plan.md:184-223`) dan `multi-business-open-decisions.md:44-54,75-91`.
- `multi-business-open-decisions.md:144` menegaskan **belum ada yang approved**. Build 1 tidak boleh dianggap "keputusan final".

**M2 — Context super-admin tidak punya membership → `isActiveMembership()` selalu false.**
- Bukti: `app/Http/Middleware/ResolveBusinessContext.php:32-40` (`setActive($business)` tanpa membership); `app/Services/BusinessContext.php:53-57`.
- Dampak: kode Fase 2+ yang mengandalkan `isActiveMembership()`/`belongsToUser()` akan memperlakukan super-admin sebagai "bukan member". Perlu konvensi eksplisit (mis. cek `isSuperAdmin()` lebih dulu) agar tidak salah tolak.

**M3 — Cakupan test keamanan belum lengkap untuk SEC-02/03/18/19.**
- SEC-03 (eskalasi outlet saat **update**) — kode memang menerapkan `UserRequest` di update (`app/Http/Controllers/UserController.php:123,155`), tetapi test hanya menguji **store** (`tests/Feature/Security/UserPrivilegeAssignmentTest.php:76-93`). Update belum diuji.
- SEC-18 (ganti `business_id` **via body payload**) — hanya query param yang diuji (`tests/Feature/Tenancy/BusinessContextMiddlewareTest.php:112-124`). Payload body & modul lain belum.
- SEC-19 (FK lintas business) — **belum diimplementasikan**, wajar karena Build 4+, tetapi jangan diklaim tercakup.
- Lihat `multi-business-security-test-plan.md:78-87,138-142`.

**M4 — Fallback `defaultStockWarehouseFor()` mengabaikan akses (pre-existing).**
- `app/Services/OutletAccessService.php:62-65`: setelah `salesWarehousesFor()`/`warehousesFor()` kosong, fallback memilih **sembarang** warehouse aktif (`Warehouse::active()->where('type','main')...`).
- Bukan regresi Build 1 (perilaku lama), tetapi membuat perbaikan fail-closed `warehousesFor()` bisa "bocor" lewat jalur default stock. Kandidat perbaikan saat isolasi (Fase 5), bukan sekarang.

### 2.3 RENDAH / INFORMATIF

**L1 — Rollback migrasi `outlets.business_id` belum diuji pada MySQL/SQLite nyata.**
- `database/migrations/2026_10_10_150300_add_business_id_to_outlets_table.php:26-36`: `dropForeign` + `dropColumn`. RefreshDatabase hanya menjalankan `up()`. Tidak dijalankan saat review (sesuai aturan).

**L2 — `User::businesses()` pivot datetime tidak di-cast.**
- `app/Models/User.php:138-143` mendefinisikan pivot `invited_at`/`joined_at` tanpa cast; nilai terbaca sebagai string. Kosmetik.

**L3 — Guard `Schema::hasColumn` pada migrasi outlets bisa melewatkan FK/index** bila kolom sudah ada tanpa FK (`...150300...:11-13`). Hanya relevan untuk state parsial.

**L4 — `Business::casts()` kosong** (`app/Models/Business.php:30-33`) — bisa dihapus, tidak berbahaya.

**L5 — `whereRaw('1 = 0')`** (`app/Services/OutletAccessService.php:87`) valid lintas-DB; alternatif `whereIn('id', [])` lebih idiomatik. Tidak ada bug.

---

## 3. Bukti (path, class, method, test)

### Skema & migrasi
- `database/migrations/2026_10_10_150216_create_businesses_table.php` — `slug(180) unique`, `owner_user_id` FK `users` `nullOnDelete`, `status` default `active`, index `status`.
- `database/migrations/2026_10_10_150300_add_business_id_to_outlets_table.php` — `business_id` nullable FK `businesses` `nullOnDelete`, index.
- `database/migrations/2026_10_10_150736_create_business_user_table.php` — FK `business_id`/`user_id` `cascadeOnDelete`, `default_outlet_id`/`invited_by_user_id` `nullOnDelete`, `unique(business_id,user_id)`, index `(user_id,status)`, `(business_id,status)`.
- Urutan aman: `businesses` → `outlets.business_id` → `business_user`.
- Verifikasi runtime: `tests/Feature/Tenancy/BusinessMembershipTest.php:30-38` (`Schema::hasTable`/`hasColumn`).

### Relasi
- `app/Models/Business.php:35-60` (`owner`, `memberships`, `users` pivot `business_user`, `outlets`, `scopeActive`).
- `app/Models/BusinessUser.php:41` (`$table='business_user'`), `:62-90` (relasi + scope).
- `app/Models/User.php:133-148` (`memberships`, `businesses`, `membershipsInActiveState`).
- `app/Models/Outlet.php` (`business_id` fillable, `business()`, `scopeForBusiness`).

### Tenant context
- `app/Services/BusinessContext.php` — holder; `clear()` `:22-26`; `isActiveMembership()` `:53-57`; `belongsToUser()` `:59-64`.
- `app/Services/BusinessMembershipResolver.php:12-50` — verifikasi membership aktif **dan** business `active`; `soleActiveMembershipFor()` hanya bila tepat 1.
- `app/Http/Middleware/ResolveBusinessContext.php` — sumber hanya session `active_business_id` (`:63-72`); `clear()` di awal (`:21`); super-admin hanya boleh pilih business eksplisit (`:31-40`).
- `app/Http/Middleware/EnsureActiveBusiness.php:16` — abort 403 tanpa context.
- Binding `scoped`: `app/Providers/AppServiceProvider.php:21`. Alias: `bootstrap/app.php:57-58`.

### Keamanan
- `app/Services/OutletAccessService.php:77-92` — fail-closed multi-outlet; bypass single-outlet via `legacySingleOutletBypass()` `:189-193` + `config/security.php:23-29`.
- `app/Http/Requests/UserRequest.php:38` (role `exists`), `:75-77` (blokir super-admin), `:79-88` (batasi outlet ke `accessibleOutlets`).

### Test
- `tests/Feature/Tenancy/BusinessMembershipTest.php` (8), `tests/Feature/Tenancy/BusinessContextMiddlewareTest.php` (10), `tests/Feature/Inventory/OutletAccessNoAssignmentTest.php` (4), `tests/Feature/Security/UserPrivilegeAssignmentTest.php` (5) = **27 test baru**.
- Middleware **tidak** terpasang ke route: grep `business`/`business.required`/`active_business_id` hanya menemukan definisi alias di `bootstrap/app.php:58` (tidak ada route).

---

## 4. Verifikasi Status / Isu

### Terverifikasi (confirmed)
1. Skema & FK/unique/index benar; urutan migrasi benar.
2. Context tidak bocor antar-request — `test_context_does_not_leak_between_requests` (`BusinessContextMiddlewareTest.php:157-176`) + binding `scoped` + `clear()`.
3. Non-super-admin tanpa membership tidak dapat konteks — `:95-110`; super-admin tidak otomatis owner — `:126-139`.
4. `business_id` query param tidak dipercaya — `:112-124`.
5. Fail-closed `warehousesFor()` multi-outlet — `OutletAccessNoAssignmentTest.php:29-39`; single-outlet tetap kompatibel — `:41-52`; bypass bisa dimatikan — `:54-64`.
6. Anti-eskalasi role/outlet saat **store** — `UserPrivilegeAssignmentTest.php:50-114`.
7. Middleware baru tidak mengubah route existing (hanya alias terdaftar).

### Dugaan / perlu uji lebih lanjut (suspected)
1. Rollback `outlets.business_id` pada MySQL/SQLite (L1) — belum diuji.
2. Anti-eskalasi pada **update** user (SEC-03) — kode menerapkan, test belum (M3).
3. `business_id` via **body payload** (SEC-18) & FK lintas business (SEC-19) — belum diuji/tidak diimplementasikan.
4. Perilaku context super-admin di Fase 2 (M2) — belum ada test yang menegaskan `isSuperAdmin()` diprioritaskan.
5. `defaultStockWarehouseFor()` fallback (M4) — perlu uji setelah isolasi.

---

## 5. Hasil Test Aktual

```
php artisan test
Tests:  474 passed (2111 assertions)
Duration: 125.56s
```
- `TransactionFlowTest`: 7 passed (termasuk `includes legacy null warehouse in single outlet` & `excludes legacy null warehouse in multi outlet`).
- Kelompok Build 1: `tests/Feature/Tenancy` 18 passed; `OutletAccessNoAssignmentTest` 4; `UserPrivilegeAssignmentTest` 5.

### Perbandingan baseline
- **Ambigu.** Working tree sudah membawa perubahan tak-terkait Build 1 sebelum Build 1 dimulai (mis. penghapusan DineIn). Tidak ada commit bersih sebagai baseline multi-business.
- Test lama yang berubah di working tree **bukan** dari Build 1 (bukti: `tests/Feature/Inventory/OutletAccessGuardTest.php` hanya menghapus test DineIn — lihat diff). Build 1 hanya mengubah **satu** test: `tests/Feature/Transactions/TransactionFlowTest.php` (+1 baris).
- **Tidak ada kegagalan tersamarkan:** tidak ditemukan assertion yang dilonggarkan selain penyesuaian `TransactionFlowTest` (dibahas §6). Tidak ada `markTestSkipped` baru. Regresi: tidak ada.

---

## 6. Tinjauan Perubahan `TransactionFlowTest.php` (item 7)

- Perubahan: `tests/Feature/Transactions/TransactionFlowTest.php:361` → `$cashier->outlets()->attach($outletA->id, ['is_default' => true]);` (menetapkan cashier ke outlet A tempat warehouse berada).
- **Tujuan legacy-null tetap terjaga:** dengan 2 outlet aktif, `TransactionController::history()` (`app/Http/Controllers/Apps/TransactionController.php:805-812`) tidak menambahkan `orWhereNull('warehouse_id')` karena `Outlet::active()->count() > 1`, sehingga `TRX-LEGACY` (warehouse null) tetap dikecualikan, dan `TRX-OUTLET` tetap terlihat karena warehouse A ada di `warehousesFor()`.
- **Alasan penyesuaian:** test lama bergantung pada perilaku **rentan** (cashier tanpa outlet melihat warehouse outlet). Perilaku itu justru yang ditutup SEC-01. Setelah fix, tanpa assignment `warehousesFor()` kosong → test lama mustahil lulus tanpa mengembalikan celah.
- **Kesimpulan:** bukan "ubah test agar lulus" secara artifisial; ini adaptasi yang mempertahankan maksud (multi-outlet mengecualikan legacy-null) dan menyerahkan skenario "unassigned" ke `OutletAccessNoAssignmentTest.php` yang menguji negatif. **Disetujui** dengan catatan: perubahan perilaku POS (fail-closed) harus tercatat di release notes.

---

## 7. Status Kompatibilitas POS Existing

- **Single-outlet:** tidak berubah (bypass `legacySingleOutletBypass()` aktif). Diuji `OutletAccessNoAssignmentTest.php:41-52`.
- **Multi-outlet dengan user ter-assign:** tidak berubah (filter `whereIn('outlet_id', $outletIds)` sama).
- **Multi-outlet dengan user TANPA outlet:** berubah — dari "melihat semua warehouse" menjadi "melihat kosong". **Ini perbaikan keamanan yang disengaja**, namun secara POS merupakan perubahan perilaku yang harus dikomunikasikan.
- Dampak menyebar ke banyak konsumen `warehousesFor()` (Dashboard, Reports, StockOpname, GoodsReceiving, Receivable, SupplierReturn, HandleInertiaRequests) — semua manfaat perbaikan, tidak ada yang rusak menurut suite test (`grep` di §3).
- Full suite hijau → tidak ada regresi POS terdeteksi.

---

## 8. Kesiapan Fondasi untuk Build 2

| Aspek | Nilai | Catatan |
|---|---|---|
| Skema tenant | Siap | Additive, FK/index benar |
| Model & relasi | Siap | Konsisten |
| Tenant context | Siap (dasar) | Belum terpasang route; super-admin tanpa membership |
| Anti-eskalasi role/outlet | Sebagian | Store tertutup; update belum diuji |
| Business default | **Belum** | Prasyarat backfill (Fase 3) |
| Isolasi data | **Belum** | Fase 4-5 |
| Validasi FK lintas business | **Belum** | Fase 2+ |
| Keputusan arsitektur | **Belum** | Q2/Q3/Q7/Q9/Q14 |

**Verdict:** fondasi **cukup kuat** untuk melanjutkan, tetapi **belum lengkap** untuk backfill (Fase 3) karena tidak ada business default dan keputusan ownership.

---

## 9. Blocker & Keputusan yang Harus Dikonfirmasi

Wajib diputuskan **sebelum Build 4+** (rujuk `multi-business-open-decisions.md`, semuanya masih `PERLU KEPUTUSAN`):

- **Q2** (owner membership vs global; super-admin global) — memengaruhi M2 dan otorisasi.
- **Q3** (role per business) — sudah dipakai (`business_user.role`) tapi belum disetujui.
- **Q7** (instalasi existing = satu business) — menentukan bentuk backfill & business default.
- **Q9** (data existing tanpa ownership) — menentukan assignment legacy.
- Pendukung: **Q5** (setting global vs per business), **Q10** (status DineIn), **Q12** (`audit_logs.business_id`), **Q13** (global scope vs eksplisit), **Q14** (membership vs Spatie teams).

Prinsip: **jangan backfill** dan **jangan tentukan kepemilikan data lama otomatis** sebelum Q7/Q9 dikonfirmasi.

---

## 10. Rekomendasi Urutan Implementasi Build 2+

1. **Kunci keputusan** Q2, Q3, Q7, Q9 (dan Q5/Q10/Q12/Q13/Q14) — catat sebagai `Keputusan` bertanggal di `multi-business-open-decisions.md`.
2. **Tutup celah test SEC** (tanpa arsitektur baru): tambah test update-user (SEC-03) & body-payload `business_id` (SEC-18) untuk memastikan `UserRequest`/context benar pada update.
3. **Business default + seeder** (lengkapi kriteria Fase 1 plan), lalu **backfill** `outlets.business_id` (Fase 3) dengan command audit + `inventory:reconcile`.
4. **Pasang middleware `business`** pada route (Fase 2), tambah policy + validasi FK lintas business (SEC-19), prioritaskan `isSuperAdmin()` atas `isActiveMembership()` (M2).
5. **Isolasi master data** (Q4), lalu **POS/transaksi/stok/pembayaran**, lalu **laporan/import/API/webhook**, yang terakhir **UI** (Fase 8).
6. Setiap fase: jaga suite existing hijau + SEC terkait lulus (kriteria `multi-business-security-test-plan.md:201-206`).

---

## 11. Perubahan pada Tugas Ini

- **Kode:** tidak ada perubahan.
- **Database:** tidak ada migrasi/seeder dijalankan.
- **Deployment:** tidak ada.
- **Dokumen:** hanya menambah file baru ini (`docs/architecture/multi-business-phase-1-review.md`). Tidak menimpa dokumen lama.

---

## 12. Kesimpulan

Build 1 (fondasi multi-business) **lolos review teknis**: skema, relasi, context, dan perbaikan keamanan P0 terverifikasi, dengan suite test hijau (474 passed). Fondasi **cukup untuk membangun isolasi**, tetapi **belum aman untuk diaktifkan** dan **belum boleh backfill** sampai keputusan Q2/Q3/Q7/Q9 dikonfirmasi. Tidak ada bug Build 1 yang memerlukan perbaikan kode darurat pada tugas ini.

**Berhenti di sini. Tidak melanjutkan ke Build 2.**
