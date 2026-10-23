# Cashier Shifts

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Mengontrol sesi kerja kasir agar transaksi kasir memiliki konteks shift yang jelas, termasuk cash opening, cash closing, dan summary operasional.

## Fitur Saat Ini

- open shift
- list shift
- detail shift
- close shift
- force close untuk user berwenang
- ringkasan expected cash, actual cash, selisih, transaksi, dan sales return
- riwayat kas masuk/keluar per shift (waktu, tipe, nominal, keterangan, pencatat)
- kolom total kas masuk dan kas keluar di tabel histori shift

## Halaman dan Route

- `dashboard/cashier-shifts`
- `cashier-shifts.store`
- `cashier-shifts.show`
- `cashier-shifts.close`

## Permission

- `cashier-shifts-access`
- `cashier-shifts-open`
- `cashier-shifts-close`
- `cashier-shifts-force-close`

## Alur User

1. kasir membuka shift dengan opening cash
2. route transaksi tertentu mewajibkan shift aktif
3. selama shift aktif, transaksi dan sales return tercatat dalam konteks shift
4. kasir dapat mencatat kas masuk/keluar beserta keterangannya selama shift aktif
5. saat penutupan, kasir mengisi actual cash
6. sistem menghitung selisih dan summary shift, lalu menyimpan total kas masuk/keluar

## Integrasi Data

- `cashier_shifts` (termasuk `cash_in_total` dan `cash_out_total`)
- `shift_cash_movements`
- transaksi cash / non-cash
- sales returns
- middleware `active_shift`

## Efek Bisnis Penting

- tanpa shift aktif, operasi inti transaksi diblokir
- close shift dipakai untuk rekonsiliasi cash operasional
- switch **outlet** dikunci selama shift aktif (route `outlet.switch` menolak; komponen
  `OutletSwitcher` menjadi read-only)
- switch **business** juga dikunci bila ada shift aktif di business lain (route `business.switch`
  mengembalikan 422 via `OutletAccessService::hasActiveShiftInOtherBusiness`; `BusinessSwitcher`
  menjadi read-only)

## Batasan Saat Ini

- shift tidak dimaksudkan sebagai workflow approval
- force close dibatasi untuk user tertentu

## File Sentral

- `app/Http/Controllers/Apps/CashierShiftController.php`
- `app/Services/CashierShiftService.php`
- `app/Http/Middleware/EnsureActiveCashierShift.php`
