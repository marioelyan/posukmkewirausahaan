# Settings & Payments

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Mengelola konfigurasi bisnis dan pembayaran yang dipakai aplikasi secara operasional.

## Fitur Saat Ini

- payment gateway settings
- bank account management
- store profile settings
- target penjualan

## Halaman dan Route

- `dashboard/settings/payments`
- `dashboard/settings/bank-accounts`
- `dashboard/settings/store`
- `dashboard/settings/target`

## Permission

- `payment-settings-access`
- `dashboard-access` untuk profil toko dan target

## Alur User

1. admin mengatur gateway pembayaran
2. admin menambah rekening bank aktif
3. admin mengatur profil toko
4. admin mengisi target penjualan

## Integrasi Data

- `payment_settings`
- `bank_accounts`
- `settings`
- transaksi dan receivable/payable payment yang memakai bank account

## Efek Bisnis Penting

- payment gateway settings memengaruhi checkout
- bank account aktif memengaruhi transfer manual dan pembayaran finansial lain
- `APP_URL` yang salah dapat membuat webhook tidak usable

## Batasan Saat Ini

- payment gateway tetap memerlukan konfigurasi provider di luar aplikasi
- warning webhook ditampilkan, tetapi deployment publik tetap tanggung jawab environment

## File Sentral

- `app/Http/Controllers/Apps/PaymentSettingController.php`
- `app/Http/Controllers/Apps/BankAccountController.php`
- `app/Http/Controllers/Apps/SettingController.php`
