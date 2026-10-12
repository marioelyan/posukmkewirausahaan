# ADR-04 — Business Settings & Store Profile (Q5)

- **Status:** Proposed — **menunggu persetujuan pemilik proyek**.
- **Tanggal audit:** 2026-10-11
- **Basis bukti:** `app/Models/Setting.php`, migrasi settings, `app/Http/Controllers/Apps/SettingController.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Http/Controllers/Api/AuthController.php`, `app/Services/TaxService.php`.
- **Terkait:** `multi-business-open-decisions.md` (Q5).
- **Catatan:** Tidak ada setting yang dipindahkan, tidak ada kolom/skema yang diubah, tidak ada backfill. Tidak ada perubahan kode pada fase ini.

## 1. Konteks

Konfigurasi aplikasi disimpan di tabel `settings` (`key`, `outlet_id`, `value`, `description`). Sebagian key bersifat platform/global, sebagian bersifat toko/outlet. Setelah pemisahan per business, perlu ditentukan key mana yang global, mana per-business, dan mana per-outlet — tanpa mengasumsikan seluruh setting harus dipindahkan ke `Business`.

## 2. Kondisi aktual yang ditemukan (FAKTA)

### 2.1 Skema (FAKTA)

- `2025_12_25_200000_create_settings_table.php`: `settings(key UNIQUE, value, description)`.
- `2026_09_14_000004_add_outlet_id_to_settings_table.php`: menambah `outlet_id` (nullable, FK, `nullOnDelete`), **drop unique `key`**, dan menambah **unique komposit `(key, outlet_id)`**.
- **Tidak ada kolom `business_id`.** Dimensi tenancy yang tersedia hanya `outlet_id` (via outlet → business).

### 2.2 Pembacaan/penulisan (FAKTA)

- `Setting::get($key)` → `where('key',$key)->whereNull('outlet_id')` → **baris global**.
- `Setting::getForOutlet($key, $outlet)` → baris outlet, fallback ke global.
- `Setting::set($key)` → menulis baris global (`outlet_id = null`).
- `Setting::setForOutlet($key, $value, $outlet)` → baris outlet; **bila `$outlet` null → fallback menulis global**.

### 2.3 Key global-only (dibaca/ditulis tanpa dimensi outlet) (FAKTA)

| Key | Pengguna | Sifat |
|---|---|---|
| `app_setup_completed` | `EnsureNotInstalled` | platform (harus global) |
| `setup_warehouse_id` | Setup wizard | platform/instalasi |
| `store_npwp`, `store_nib` | `SettingController::updateStoreProfile` (`Setting::set`) | identitas toko/legal → seharusnya per-business |
| `tax_default_rate` | `SettingController::updateStoreProfile` (`Setting::set`, global); dibaca `TaxService::getForOutlet` (fallback global) | pajak → per-business/outlet |
| `discount_approval_threshold`, `discount_approval_percent_threshold` | `Transaction` | kebijakan toko |
| `loyalty_*` (enable_earn, enable_redeem, earn_rate_amount, redeem_point_value, tier_*_threshold) | `LoyaltyService` | kebijakan toko/CRM |
| `monthly_sales_target` | `SettingController` (`getForOutlet`) | per-outlet |

### 2.4 Key outlet-scoped (dibaca/ditulis via `*ForOutlet`) (FAKTA)

`store_name`, `store_logo`, `store_address`, `store_phone`, `store_email`, `store_website`, `store_city`, `tax_default_rate`, `printer_auto_print`, `printer_paper_size`, `wa_enabled`, `wa_service_url`, `wa_auto_invoice`, `wa_auto_reminder`, `monthly_sales_target`.

### 2.5 Inkonsistensi yang dapat dibuktikan (gap tenancy)

1. **`updateStoreProfile` menulis sebagian setting secara global.** `store_npwp`, `store_nib`, dan `tax_default_rate` memakai `Setting::set(...)` (baris global). Saat tenancy aktif, admin business A yang mengubah NPWP/NIB/tarif pajak **menimpa nilai global** yang dipakai business lain. (Baris store profile lain sudah per-outlet via `setForOutlet(..., $outlet)`.)
2. **Fallback global pada pembacaan outlet-scoped.** `Setting::getForOutlet` jatuh ke baris global bila outlet belum punya nilai. Karena baris global tidak punya dimensi business, nilai bisa berasal dari business lain / nilai setup lama.
3. **`Api\AuthController::me` mengembalikan store profile global.** `store_name/address/phone` diambil via `Setting::get` (global), sedangkan web memakai `getForOutlet(activeOutlet)`. Saat tenancy aktif, endpoint `me` dapat menampilkan profil toko business lain.
4. **`BusinessBackfillCommand` memakai `Setting::get('store_name')`** sebagai fallback nama business default — acceptable untuk bootstrapping, tetapi menegaskan store profile saat ini global.
5. **Tidak ada isolasi key kebijakan toko** (`discount_approval_*`, `loyalty_*`): semua business berbagi satu nilai.

## 3. Matriks setting (kepemilikan yang seharusnya)

| Setting | Lokasi sekarang | Pemilik yang seharusnya | Global? | Dampak jika dipindah | Migration/backfill |
|---|---|---|---|---|---|
| `app_setup_completed` | global | platform | Ya | tidak | tidak |
| `setup_warehouse_id` | global | platform | Ya | tidak | tidak |
| `store_name/logo/address/phone/email/website/city` | outlet (fallback global) | per-business/outlet | Tidak | profil toko terpisah per business | opsional |
| `store_npwp`, `store_nib` | global | per-business (legal) | Tidak | identitas legal terpisah | ya (business_id) |
| `tax_default_rate` | global + outlet | per-business/outlet | Tidak | pajak terpisah | ya |
| `discount_approval_*` | global | per-business | Tidak | kebijakan approval terpisah | ya |
| `loyalty_*` | global | per-business | Tidak | kebijakan loyalty terpisah | ya |
| `printer_*` | outlet | per-outlet | Tidak | tetap | tidak |
| `wa_*` | outlet | per-outlet | Tidak | tetap | tidak |
| `monthly_sales_target` | outlet | per-outlet | Tidak | tetap | tidak |

## 4. Alternatif desain

| Opsi | Deskripsi | Keamanan | Operasional | Biaya |
|---|---|---|---|---|
| **A. Pertahankan global** | Semua business berbagi setting | Rendah | Tidak berubah | Nol |
| **B. Per-outlet saja** | Semua setting business disimpan via `outlet_id`; wajib ada active outlet | Sedang–Tinggi | Perlu memastikan setiap business punya outlet; key legal jadi per-outlet | Sedang |
| **C. Tambah `business_id` pada settings (rekomendasi Q5)** | Resolusi: outlet → business → platform; key platform tetap global | Tinggi | Jelas & eksplisit | Sedang (migrasi + backfill + resolver) |
| **D. Hybrid tanpa migrasi** | Pindahkan key business ke outlet-level via `setForOutlet`; sisakan key platform global | Sedang | Cepat, tanpa skema, tetapi bergantung active outlet & tidak menutup fallback global | Rendah |

## 5. Rekomendasi

1. **Klasifikasi key:** tetapkan daftar **platform-global** (`app_setup_completed`, `setup_warehouse_id`, versi) vs **business/outlet** (store profile, NPWP/NIB, pajak, discount approval, loyalty, printer, WA, target). Rekomendasi opsi **C**.
2. Jangka pendek **tanpa** mengubah kebijakan penyimpanan yang belum disetujui: jangan pindahkan setting apa pun.
3. **Keputusan yang diminta (Q5):** apakah profile/legal/pajak disimpan per-business (kolom `business_id`) atau per-outlet; dan resolusi/fallback yang diinginkan.

## 6. Perbaikan yang aman (TIDAK dilakukan pada fase ini)

Kandidat yang terlihat aman namun **bergantung kebijakan Q5**, sehingga tidak diimplementasikan:
- Mengubah `store_npwp`/`store_nib`/`tax_default_rate` dari `Setting::set` → `setForOutlet(activeOutlet)`. Ini mengubah pemilik data (per-outlet vs per-business) dan tidak menutup fallback global saat active outlet null → keputusan kebijakan.
- Menyelaraskan `Api\AuthController::me` ke `getForOutlet` + active outlet. Mengubah semantik respons API dan bergantung resolusi active outlet → keputusan kebijakan.

Catatan: ada **satu koreksi kecil yang dipertimbangkan** — menambahkan validasi/kegagalan eksplisit saat active outlet null pada update store profile — namun ini pun menyentuh alur setup (yang memang boleh menulis global saat belum ada outlet), sehingga tetap ditahan sampai Q5.

## 7. Kebutuhan migration / backfill (DRAFT — tidak dibuat, tidak dijalankan)

1. `ALTER TABLE settings ADD business_id BIGINT UNSIGNED NULL` + index.
2. Backfill `business_id` untuk baris yang bukan platform-global:
   - baris dengan `outlet_id` → business via outlet;
   - baris global dari instalasi legacy → business default (Q7) bila disetujui.
3. Sesuaikan unique index: `(key, business_id, outlet_id)` menggantikan `(key, outlet_id)`.
4. Resolusi baca: `outlet` → `business` → `global` → default.
5. **Tidak dibuat/dijalankan pada fase ini.**

## 8. Kompatibilitas legacy

- Flag off: perilaku lama dipertahankan (baris global, fallback outlet→global).
- Flag on (setelah Q5 & migrasi): isolasi penuh per business/outlet.
- Selama Q5 belum diputuskan, tidak ada perubahan — status quo.

## 9. Keputusan yang menunggu persetujuan

- **Q5:** Skema & kepemilikan settings per-business/outlet vs global; resolusi/fallback; perlakuan key legal & pajak.

## 10. Status implementasi pada fase ini

- **Tidak ada perubahan kode** untuk ADR-04. Gap isolasi settings didokumentasikan; perbaikannya menunggu Q5.
