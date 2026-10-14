# Business Membership (Anggota Business)

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Mengelola siapa yang boleh mengakses sebuah business dan dengan peran apa. Sebuah **membership**
(`business_user`) adalah sumber kebenaran tenancy untuk wewenang di dalam business
(role membership → `BusinessRoleCapabilities`), sedangkan **akses outlet** (`user_outlets`) menentukan
data apa yang terlihat. Keduanya dikelola bersama di satu halaman agar tidak terjadi member yang punya
permission tapi tidak punya outlet yang bisa diakses.

## Fitur Saat Ini

- halaman detail business: ringkasan (outlet & jumlah anggota) + daftar anggota
- tambah anggota: pilih akun (kandidat = user non-super-admin yang belum menjadi anggota), pilih role
  dan status, pilih outlet + outlet default
- ubah anggota: role, status, outlet access, dan default outlet
- hapus anggota: membership dihapus + outlet business dicabut dari user
- pencatatan audit untuk tambah/ubah/hapus anggota

## Halaman dan Route

Grup `platform.admin` (super-admin platform; berada di luar grup `business.required` supaya tetap
bisa dikelola tanpa konteks business aktif):

- `dashboard/businesses/{business}` — `businesses.show`
- `POST dashboard/businesses/{business}/members` — `businesses.members.store`
- `PATCH dashboard/businesses/{business}/members/{member}` — `businesses.members.update`
- `DELETE dashboard/businesses/{business}/members/{member}` — `businesses.members.destroy`

Semua operasi tulis anggota memakai middleware `step_up` (konfirmasi password segar), sama seperti
operasi tulis platform lainnya.

## Role & Status Anggota

Role (konstanta `BusinessUser::ROLES`):

| Role | Pemetaan capability |
|------|---------------------|
| `owner` | semua permission kecuali administrasi platform lintas business (`users-*`, `roles-*`, `permissions-*`, `financial-ownership-*`) |
| `manager` | role Spatie `manager` (scope operasional penuh, tanpa admin platform) |
| `staff` | role Spatie `cashier` (POS + read-mostly) |

Status (konstanta `BusinessUser::STATUSES`): `invited`, `active`, `suspended`, `revoked`.
Hanya status `active` yang memberi akses (resolver membership menolak non-active).

## Aturan & Validasi

- kandidat user **tidak boleh super-admin** (jalur platform AUTH-01) dan belum menjadi anggota business itu
- satu user = satu baris per business (unique `business_id,user_id`)
- semua `outlet_ids` harus milik business tersebut dan aktif; `default_outlet_id` harus termasuk
  outlet yang dipilih
- jika `owner_user_id` business terisi, pemilik tidak boleh diturunkan/dinonaktifkan dari role `owner`
  dan tidak bisa dihapus — harus tetapkan owner baru dulu
- super-admin platform tidak pernah menjadi anggota

## Akses Outlet

`user_outlets` disinkronkan **khusus outlet business ini**: outlet dari business lain tempat user
menjadi anggota tidak disentuh. Menambah anggota tanpa outlet berarti member tersebut tidak melihat
outlet/warehouse apa pun di business itu. Gunakan opsi outlet pada form tambah/ubah untuk membiarkan
member memilih outlet/perspektif (default outlet).

## Audit

Event audit (modul `business`): `business.member.added`, `business.member.updated`,
`business.member.removed` — mencatat `member_user_id`, role, status, dan `outlet_ids` (before/after).

## Test

- `tests/Feature/Business/BusinessMembershipAdminTest.php` — CRUD membership platform admin,
  validasi, isolasi business, proteksi owner, dan syarat `step_up`.

## File Sentral

- `app/Http/Controllers/Apps/BusinessMemberController.php`
- `app/Http/Controllers/Apps/BusinessAdminController.php` (metode `show`)
- `resources/js/Pages/Dashboard/Businesses/Show.jsx`
- `app/Support/Tenancy/BusinessRoleCapabilities.php`
- `app/Services/BusinessMembershipResolver.php`