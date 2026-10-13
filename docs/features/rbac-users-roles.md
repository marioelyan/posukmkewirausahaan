# RBAC, Users, Roles

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Mengatur kontrol akses berbasis role dan permission untuk semua modul dashboard.

## Fitur Saat Ini

- user management
- role management
- permission list
- route protection dengan middleware permission
- permission map dibagikan ke frontend via Inertia

## Halaman dan Route

- `dashboard/users`
- `dashboard/roles`
- `dashboard/permissions`
- `dashboard/businesses/{business}` — manajemen anggota business (lihat `docs/features/business-membership.md`)

## Permission Umum

Setiap modul memakai permission sendiri, contohnya:

- `transactions-access`
- `sales-returns-*`
- `stock-opnames-*`
- `cashier-shifts-*`
- `audit-logs-access`

## Role Bawaan

`RoleSeeder` menyiapkan role berikut:

- **`super-admin`** — bypass seluruh permission, akses semua outlet.
- **`manager`** — akses operasional penuh lintas outlet yang ditugaskan (produk, stok, purchasing,
  transaksi, laporan, CRM, diskon), **tanpa** administrasi `users`/`roles`/`permissions`/`outlets`
  dan tanpa update kredensial payment gateway. Dibuat oleh `RoleSeeder::createManagerRole()`.
- **`cashier`** — transaksi POS, buka/tutup shift, tambah pelanggan, dan bayar piutang/hutang.
- Role per modul lain (mis. `products-access`, `transactions-access`) dibuat otomatis dari pola
  permission.

## Alur Otorisasi

1. permission diseed di `PermissionSeeder`
2. role disusun di `RoleSeeder`
3. user admin dibuat melalui first-install setup wizard di `/setup`
4. route memakai middleware `permission:*`
5. frontend membaca map permission dari `HandleInertiaRequests`

## Akun Platform vs Anggota Business (multi-business)

Saat `MULTI_BUSINESS_ENABLED=true`, halaman **Users** (`dashboard/users`) dikhususkan untuk akun
platform dengan **role global (Spatie)** dan **tidak** menulis membership `business_user`. Akses
seorang user ke sebuah business diatur lewat **Businesses → Show** (manajemen anggota): tambah/ubah/
hapus membership plus akses outlet (`user_outlets`) — detail di `docs/features/business-membership.md`.

- **Role global (Spatie)** = sumber wewenang route (`permission:*`). Contoh: role global `manager`
  dapat membuka modul Product/Category; role global `cashier` tidak. Role global diubah via halaman Users.
- **Role membership (business)** = pembatas per business (`business.role` /
  `EnforceBusinessRoleCapability`). Membership **tidak bisa menaikkan** wewenang di atas role global —
  efektifnya wewenang = irisan keduanya. Sebaiknya selaraskan keduanya, mis. user global `manager`
  dengan membership `manager`/`owner` di business terkait.
- `UserController` menyaring pilihan outlet sejalan dengan business aktif agar form tidak menawarkan
  outlet yang ditolak validasi (`outlet_ids` harus milik business yang sama).

## Catatan Super Admin

- user `super-admin` mendapat role `super-admin`
- backend memperlakukan role `super-admin` sebagai bypass permission yang konsisten untuk `can`, `canAny`, dan middleware Spatie
- `UserSeeder` hanya digunakan untuk data demo/test, bukan oleh `DatabaseSeeder`
- cache permission Spatie harus di-reset saat seeding agar permission baru terbaca konsisten
- role lama `permission-access` dinormalisasi ke `permissions-access` saat seeding agar naming RBAC tidak ambigu

## Integrasi Frontend

Frontend membaca:

- `auth.permissions`
- `auth.super`

Ini dipakai untuk menampilkan atau menyembunyikan menu dan action tertentu.

Helper frontend utama:

- `resources/js/Utils/authorization.js`
- `resources/js/Utils/Permission.jsx`

## Batasan Saat Ini

- backend tetap menjadi sumber kebenaran utama
- frontend hanya untuk gating UI, bukan keamanan final

## File Sentral

- `database/seeders/PermissionSeeder.php`
- `database/seeders/RoleSeeder.php`
- `database/seeders/UserSeeder.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Http/Controllers/Apps/BusinessMemberController.php`
- `resources/js/Utils/Menu.jsx`
