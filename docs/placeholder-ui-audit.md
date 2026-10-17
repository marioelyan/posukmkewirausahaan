# Audit UI Placeholder — Aset SVG yang Perlu Disiapkan

Audit permukaan UI yang masih menampilkan grafis placeholder (bukan aset desain final).
Daftar ini disusun agar aset SVG final bisa disiapkan dan tinggal ditukar.

Legenda prioritas:

- **Wajib** — placeholder terlihat jelas di UI publik/aplikasi.
- **Opsional** — hanya muncul sebagai fallback jarang / polish.

---

## 1. Brand Mark (huruf "K" gradient) — **SELESAI** ✅

Dulu: kotak gradient navy dengan glyph huruf **"K"** dari Tabler (`IconLetterK`).
Sekarang: fallback platform memakai aset final `public/images/icon.svg`
(perangkat POS, gradien biru `#2563EB`) lewat `BRAND.mark`.

- Kode: `resources/js/Components/BrandLogo.jsx` — fallback `IconLetterK` dihapus,
  diganti `<img src={BRAND.mark}>`; prop `variant` yang tak terpakai ikut dihapus.
- Sumber tunggal: `BRAND.mark` di `resources/js/config/brand.js`.
- Aset `public/images/brand-mark.svg` yang dulu diminta **tidak diperlukan** —
  `icon.svg` dipakai ganda sebagai favicon/PWA dan brand mark in-app.
- Urutan prioritas tetap: logo toko (Settings → Store) → inisial nama toko →
  brand platform.

**Fallback aktif saat:** store profile belum punya logo maupun nama (halaman
publik, auth, dan wizard setup).

| Lokasi render | Ukuran tampil | Rounded |
|---|---|---|
| `Pages/Welcome.jsx` — hero landing | 72px | rounded-2xl |
| `Layouts/PublicLayout.jsx` — nav publik + footer | 36px / 32px | rounded-xl / rounded-lg |
| `Layouts/GuestLayout.jsx` — halaman auth | 48px | rounded-xl |
| `Layouts/AuthenticatedLayout.jsx` — profile | 36px | rounded-xl |
| `Layouts/POSLayout.jsx` — header POS | 36px | rounded-xl |
| `Components/Dashboard/Sidebar.jsx` — header sidebar | 32px | rounded-lg |
| `Components/Dashboard/Navbar.jsx` — topbar dashboard | 36px | rounded-xl |
| `Pages/Setup/Wizard.jsx` — wizard instalasi | 48px | rounded-xl |
| `Pages/Auth/{Login,Register,ForgotPassword,ConfirmPassword}.jsx` | 48px | rounded-xl |

Tidak ada aset tambahan yang diminta.

## 2. Ikon Aplikasi / PWA / Favicon — **SELESAI** ✅

Aset final (ikon perangkat POS, gradien `#2563EB`) sudah dipasang:

| File | Spesifikasi | Status |
|---|---|---|
| `public/images/icon.svg` | 512×512, favicon modern (`type="image/svg+xml"`) | ✅ diganti |
| `public/images/icon-192.png` | 192×192 — apple-touch-icon + manifest | ✅ diregenerasi |
| `public/images/icon-512.png` | 512×512 — manifest PWA | ✅ diregenerasi |
| `public/favicon.ico` | fallback browser lama (16/32/48) | ✅ diregenerasi |

Tidak ada aset tambahan yang diminta.

## 3. OG Image (social preview) — **Wajib**

`public/images/og-image.svg` (+ hasil raster `og-image.png`) — 1200×630.
Sekarang masih SVG "digambar manual": gradient navy + mark K + wordmark
"KasirKwu" + domain. Sudah saya perbarui teksnya (nama + hapus slogan), tapi
desainnya masih placeholder.

**Aset diminta:** `og-image.svg` final 1200×630. PNG bisa dirender dari SVG.

## 4. Product Image Placeholder — **Wajib (jika foto produk dipakai lagi)**

`public/images/product-placeholder.svg` — 400×400, ilustrasi kotak abu
(gambar "foto produk"). Direferensikan dari `resources/js/Utils/imageUrl.js`
(`getProductImageUrl()`), namun **kedua consumer utamanya sudah dilepas**:
grid POS (`ProductGrid.jsx`) dan tabel `Pages/Dashboard/Products/Index.jsx`
(keduanya sudah di-commit: `86c1978`) tidak lagi menampilkan area gambar produk.

- Jika foto produk tidak akan ditampilkan: `imageUrl.js` + file ini bisa dihapus.
- Jika akan dipakai lagi: siapkan `product-placeholder.svg` baru 1:1.

## 5. Category Image Placeholder — **Opsional**

`Pages/Dashboard/Categories/Index.jsx` — card grid menampilkan area gambar
`aspect-[3/2]` dengan fallback `IconCategory` 48px bila kategori belum punya
gambar. Halaman Create/Edit memakai `IconPhoto` untuk area upload.

**Aset diminta (opsional):** `public/images/category-placeholder.svg` — rasio 3:2.

## 6. Empty-State Illustrations — **Opsional (polish)**

25+ halaman dashboard memakai glyph tabler `IconDatabaseOff` (24–28px) sebagai
empty state, mis. `Products/Index`, `Customers/Index`, `Transactions/History`,
`Reports/{Sales,Profit,Insights}`, `Roles/Index`, `Permissions/Index`,
`Suppliers/Index`, `CustomerVouchers/Index`, `Members/Show`, `Customers/Show`.
Dashboard (`Pages/Dashboard/Index.jsx`) memakai `IconBox` / `IconPackageOff`.

Ini bukan placeholder file — hanya ikon generik. Jika ingin ilustrasi khusus,
siapkan mis. `public/images/empty-{products,transactions,reports,customers}.svg`
(~160×120) lalu saya ganti per konteks.

---

## Ringkasan file yang perlu disiapkan

| # | File baru / isi ulang | Ukuran | Prioritas | Status |
|---|---|---|---|---|
| 1 | ~~`public/images/brand-mark.svg`~~ (dipakai `icon.svg`) | 512×512 square | Wajib | ✅ selesai |
| 2 | `public/images/icon.svg` (+192/512 png, favicon.ico) | 512×512 | Wajib | ✅ selesai |
| 3 | `public/images/og-image.svg` | 1200×630 | Wajib | ⬜ tersisa |
| 4 | `public/images/product-placeholder.svg` | 400×400 (rasio 1:1) | Wajib* | ⬜ opsional |
| 5 | `public/images/category-placeholder.svg` | rasio 3:2 | Opsional | ⬜ tersisa |
| 6 | `public/images/empty-*.svg` | ~160×120 | Opsional | ⬜ tersisa |

\* Hentikan jika foto produk tidak ditampilkan lagi di UI.

Sudah dipasang:
- fallback `BrandLogo.jsx` → `<img src={BRAND.mark}>` (huruf "K" dihapus)
- raster ikon aplikasi (icon-192/512, favicon.ico) dari `icon.svg`

Sisa: raster `og-image.png` dari `og-image.svg` final (dan opsional
`scripts/build-brand-assets.sh` untuk otomatisasi).
