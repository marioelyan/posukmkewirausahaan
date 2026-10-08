# Customers & Regions

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Menyediakan master data customer yang lebih kaya daripada POS basic, termasuk alamat wilayah Indonesia dan histori transaksi.

## Fitur Saat Ini

- CRUD customer
- create customer dari dashboard
- create customer via AJAX dari POS
- data alamat lengkap dengan wilayah Indonesia
- histori transaksi customer

## Halaman dan Route

- `dashboard/customers`
- `customers.storeAjax`
- `customers.history`
- `regions.regencies`
- `regions.districts`
- `regions.villages`

## Permission

- `customers-access`
- `customers-create`
- `customers-edit`
- `customers-delete`
- histori customer memakai `transactions-access`

## Alur User

1. user membuat atau mengedit customer
2. pilihan wilayah dimuat bertingkat dari province ke village
3. customer dapat dipakai saat checkout
4. histori transaksi customer dapat dibuka dari dashboard

## Integrasi Data

- `customers`
- transaksi terkait customer
- data wilayah dari `laravolt/indonesia`

## Batasan Saat Ini

- customer tanpa data lengkap tetap bisa dipakai transaksi
- history customer berfokus pada histori transaksi, bukan loyalty

## File Sentral

- `app/Http/Controllers/Apps/CustomerController.php`
- `app/Http/Controllers/RegionController.php`
- `resources/js/Pages/Dashboard/Customers`
