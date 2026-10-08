# Member Management

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Menyediakan modul member yang utuh di atas fondasi `customers` dan `loyalty`, tanpa membuat entitas baru terpisah dari customer inti.

## Fitur Saat Ini

- daftar member baru dari dashboard
- daftar member cepat dari POS
- upgrade customer biasa menjadi member
- pencarian member berdasarkan nama dan nomor anggota
- status aktif/nonaktif member tanpa menghapus histori
- histori transaksi, poin, voucher, dan segment member
- integrasi benefit member dengan pricing rules, loyalty points, dan voucher customer

## Halaman dan Route

- `dashboard/members`
- `members.index`
- `members.create`
- `members.store`
- `members.show`
- `members.edit`
- `members.update`
- `customers.upgrade-member`
- `customers.storeAjax`

## Permission

Modul ini reuse permission customer yang sudah ada:

- `customers-access`
- `customers-create`
- `customers-edit`
- `customers-delete`

## Alur User

1. admin mendaftarkan member dari dashboard atau POS
2. sistem otomatis menerbitkan `member_code` bila customer belum punya
3. member bisa menerima benefit pricing, voucher, dan earn/redeem poin
4. jika benefit perlu dihentikan, member cukup dinonaktifkan tanpa menghapus customer dan histori

## Integrasi Data

- `customers`
- `transactions`
- `loyalty_point_histories`
- `customer_vouchers`
- `pricing_rules`

## Catatan Operasional

- status nonaktif member tetap menyimpan nomor anggota dan histori supaya audit serta riwayat CRM tidak hilang
- POS customer picker mendukung pencarian `nama`, `telepon`, dan `member_code`
- upgrade member dari POS hanya mengubah status loyalty, tidak membuat customer baru

## File Sentral

- `app/Http/Controllers/Apps/MemberController.php`
- `app/Http/Controllers/Apps/CustomerController.php`
- `resources/js/Pages/Dashboard/Members`
- `resources/js/Components/POS/CustomerSelect.jsx`
- `resources/js/Components/POS/AddCustomerModal.jsx`
