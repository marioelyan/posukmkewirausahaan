# ADR-01 — Membership, Role, dan Authorization (Q2 / Q3 / Q14)

- **Status:** Proposed — **menunggu persetujuan pemilik proyek** (Q2, Q3, Q14 belum disetujui).
- **Tanggal audit:** 2026-10-11
- **Basis bukti:** kode aktual repository (lihat referensi file di bawah), bukan laporan terdahulu.
- **Terkait:** `docs/architecture/multi-business-open-decisions.md` (Q2/Q3/Q14), `multi-business-security-test-plan.md`.
- **Catatan:** Tidak ada keputusan bisnis yang ditetapkan oleh dokumen ini. Model yang ada **sudah** memadai; yang belum diputuskan adalah apakah role bersifat global atau per-business.

## 1. Konteks

Aplikasi POS dengan arsitektur Multi-Business. Satu user dapat bekerja pada satu atau lebih business. Otorisasi harus konsisten: siapa boleh memilih business mana, dan permission apa yang berlaku di dalam business tersebut.

## 2. Kondisi aktual yang ditemukan

### 2.1 Model membership (SUDAH ADA)

- `app/Models/BusinessUser.php` — tabel `business_user` (migrasi `database/migrations/2026_10_10_150736_create_business_user_table.php`).
  - `ROLE_OWNER | ROLE_MANAGER | ROLE_STAFF`
  - `STATUS_INVITED | STATUS_ACTIVE | STATUS_SUSPENDED | STATUS_REVOKED`
  - kolom: `business_id, user_id, role, status, default_outlet_id, invited_by_user_id, invited_at, joined_at`
  - relasi: `business()`, `user()`, `defaultOutlet()`, `invitedBy()`
- `app/Models/Business.php` — `memberships()` (hasMany BusinessUser), `users()` (belongsToMany via `business_user`, withPivot role/status), `owner_user_id`, `status` (`active|suspended|archived`)`, `scopeActive`.
- `app/Models/User.php` — `memberships()` (hasMany), `businesses()` (belongsToMany), `membershipsInActiveState()`.
- **Kesimpulan:** satu user dapat menjadi anggota **banyak** business dengan role berbeda per membership (kolom `role` ada di pivot `business_user`). Model Q3 secara skema sudah tersedia.

### 2.2 Pemilihan & validasi context

- `app/Http/Middleware/ResolveBusinessContext.php`
  - Sumber context: session `active_business_id` (prioritas) lalu header `X-Business-Id` (stateless API). Nilai header **tidak dipercaya mentah** — di-resolve ulang lewat resolver.
  - Super-admin: boleh memilih **business mana pun yang berstatus `active`**.
  - Non-super: wajib punya membership **active** untuk business itu (`BusinessMembershipResolver::activeMembershipFor`).
  - Tanpa id eksplisit: hanya auto-pilih bila user punya **tepat satu** membership active (`soleActiveMembershipFor`).
- `app/Http/Middleware/EnsureActiveBusiness.php` — no-op saat flag off; **403** saat flag on dan tanpa context (fail closed).
- `app/Services/BusinessMembershipResolver.php` — `activeMembershipFor` menolak business yang tidak `active` dan membership non-active; `accessibleBusinesses()` mengembalikan hanya membership active.
- `app/Services/BusinessContext.php` — menyediakan `role()`, `membership()`, `isActiveMembership()`, `belongsToUser()`, `outletIsInActiveBusiness()`.
- `app/Providers/AppServiceProvider.php` — `BusinessContext` di-bind sebagai **scoped** dan di-clear pada event `RequestHandled` (tidak bocor antar-request; dibuktikan `tests/Feature/Business/AuthorizationBoundaryIsolationTest.php`).

### 2.3 Sumber permission saat ini

- Spatie Laravel Permission. `User::isSuperAdmin()` = `hasRole('super-admin')`; `User::hasPermissionTo()` mengembalikan `true` untuk super-admin (bypass global), sesuai desain.
- Permission Spatie bersifat **global** — tidak ada `team_id`/`business_id` pada assignment permission.
- Token Sanctum: abilities distempel saat login dari permission Spatie + `'user:read'` (lihat `AGENTS.md`).
- Middleware `permission:*` mengevaluasi permission user **tanpa** melihat `BusinessContext::role()`.

### 2.4 Perilaku saat membership dicabut / business dinonaktifkan (terverifikasi)

- Membership `suspended`/`revoked`/`invited` → `activeMembershipFor` null → tanpa context → `business.required` 403. (Dibuktikan `tests/Feature/Business/AuthorizationBoundaryIsolationTest.php::test_suspended_business_cannot_be_selected` dan suite Tenancy.)
- Business `suspended`/`archived` → resolver menolak → 403.
- User dengan >1 membership active tanpa memilih business → `soleActiveMembershipFor` null → 403 (fail closed).

### 2.5 Inkonsistensi yang dapat dibuktikan

- **Role membership tidak dipakai untuk otorisasi.** `business_user.role` (`owner|manager|staff`) hanya menjadi *gate* akses business; permission efektif tetap Spatie global. Akibatnya, user yang punya permission Spatie `products-delete` di business A **dan** merupakan member business B dapat menghapus produk di B meskipun role membership-nya `staff` di B. Ini adalah inti Q3/Q14.
- **`owner` tidak otomatis diturunkan dari `Business.owner_user_id`** terhadap permission. `Business.owner_user_id` ada, tetapi tidak memberi hak istimewa otorisasi selain lewat membership.
- Tidak ada policy/gate yang membaca `BusinessContext::role()`.

## 3. Alternatif desain

| Opsi | Deskripsi | Keamanan | Operasional | Biaya |
|---|---|---|---|---|
| **A. Membership sebagai gate; permission Spatie global** (kondisi saat ini) | Membership hanya menentukan business mana yang bisa diakses; permission berlaku lintas business tempat user jadi member | Sedang: role per-business tidak efektif; over-privilege bila user punya permission sensitif global | Sederhana, tanpa perubahan skema | Nol |
| **B. Spatie teams (per-business roles)** | Set `team_id = business_id`, role/permission per business, token abilities distempel per business | Tinggi: otorisasi benar-benar per business | Kompleks: migrasi teams, resolver, stamping token, UI assignment | Tinggi |
| **C. Otorisasi berbasis role membership** | Middleware/policy memetakan `BusinessUser.role → set permission` dan memeriksa `BusinessContext::role()` | Tinggi untuk kebutuhan saat ini; tanpa teams Spatie | Sedang: mapping role→permission, audit, tanpa migrasi teams | Sedang |

## 4. Konsekuensi

- **A:** perilaku legacy dipertahankan; tetapi klaim "role berbeda per business" tidak terpenuhi secara otorisasi. Risiko privilege escalation antar business tempat user menjadi member.
- **B:** paling bersih untuk SaaS multi-tenant, tetapi bergantung sepuluh penuh pada konfigurasi teams dan menyentuh login, token, dan seluruh `permission:` middleware.
- **C:** peningkatan keamanan langsung tanpa migrasi teams; namun menambah satu lapisan evaluasi dan perlu keputusan pemetaan role→permission.

## 5. Rekomendasi

1. **Jangka pendek:** pertahankan model membership yang ada; jangan menambah model/Role baru (sesuai batasan fase).
2. **Keputusan yang diminta:** pilih B atau C untuk penegakan role per business (Q2/Q3/Q14).
3. Bila memilih **C**, pemetaan yang diusulkan: `owner` ≙ seluruh permission business; `manager` ≙ seluruh permission operasional tanpa permission destruktif platform; `staff`/cashier ≙ permission POS + akses. (Daftar tepatnya menunggu persetujuan.)
4. **Sampai disetujui:** fail-closed sudah berlaku untuk akses business; jangan menonaktifkan flag.

## 6. Keputusan yang memerlukan persetujuan pemilik proyek

- **Q2:** Apakah `owner` adalah role membership (bukan Spatie global) dan `super-admin` tetap operator platform global? (Rekomendasi: ya.)
- **Q3:** Apakah role boleh berbeda per business? (Rekomendasi: ya, disimpan di `business_user`.)
- **Q14:** Spatie teams vs membership-role? (Rekomendasi: membership dulu (opsi C), evaluasi teams bila perlu permission custom.)

## 7. Komponen kode & tes yang terdampak (bila disetujui)

- `app/Models/User.php`, `app/Models/BusinessUser.php`, `app/Models/Business.php`
- `app/Http/Middleware/ResolveBusinessContext.php`, `app/Http/Middleware/EnsureActiveBusiness.php`
- `app/Services/BusinessMembershipResolver.php`, `app/Services/BusinessContext.php`
- Semua route ber-`permission:*` dan `bootstrap/app.php` (alias middleware)
- `app/Http/Controllers/Api/AuthController.php` (stamping abilities)
- Tes: `tests/Feature/Business/*`, `tests/Feature/Security/UserPrivilegeAssignmentTest.php`, `tests/Feature/Tenancy/*`

## 8. Rencana migrasi (bila opsi B dipilih)

1. Tambah kolom `team_id` pada tabel Spatie (`roles`, `model_has_roles`, `model_has_permissions`).
2. Backfill `team_id` dari business default (Q7) dengan audit.
3. Ubah resolver + stamping token agar memakai `team_id = active business`.
4. Tambah composite index `(team_id, name)`.
5. **Migration hanya disiapkan setelah persetujuan; tidak dijalankan pada fase ini.**

## 9. Status implementasi pada fase ini

**Tidak ada perubahan kode** untuk ADR-01. Audit menunjukkan model yang ada sudah memadai dan konsisten; keputusan yang tersisa bersifat kebijakan (Q2/Q3/Q14). Tidak ada model membership/role/authorization baru yang ditambahkan berdasarkan asumsi.
