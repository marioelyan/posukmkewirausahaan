# Audit UI Placeholder — Aset SVG yang Perlu Disiapkan

Audit permukaan UI yang masih menampilkan grafis placeholder (bukan aset desain final).
Daftar ini disusun agar aset SVG final bisa disiapkan dan tinggal ditukar.

> **Status per 18 Okt 2026** — diverifikasi ulang terhadap kode dan isi
> `public/images/`. Selesai: item 1 (brand mark) dan 2 (ikon aplikasi/favicon/PWA).
> Belum berubah: item 3 (OG image), 4 (product placeholder), 5 (category
> placeholder), 6 (empty-state illustration). Ringkasan terverifikasi ada di
> bagian [Ringkasan terverifikasi](#ringkasan-terverifikasi-18-okt-2026).

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

## 3. OG Image (social preview) — **Wajib** ⬜ belum berubah

`public/images/og-image.svg` (+ hasil raster `og-image.png`) — 1200×630.
Diverifikasi 18 Okt 2026: desainnya **masih placeholder** dan **belum memakai
ikon baru**.

- `og-image.svg` masih memakai gradient lama `#21416D → #102138` dan mark lama
  bergradient `#456FAF → #1B3559` dengan glyph chevron — bukan ikon perangkat
  POS `#2563EB` yang sudah dipakai di favicon/PWA/brand mark in-app.
- Teks sudah dibereskan (wordmark "KasirKwu" + domain, slogan sudah hilang).
- **PNG-nya lebih tua dari SVG-nya**: `og-image.png` (14 Okt 18:17) <
  `og-image.svg` (17 Okt 16:23), jadi PNG itu **bukan hasil render SVG terbaru**.
  `resources/views/app.blade.php` menunjuk `og:image`/`twitter:image` ke PNG
  tersebut, sehingga preview sosial yang benar-benar tampil masih versi pra-rebrand.
- Belum ada script rasterisasi (`scripts/` hanya berisi `deploy-build.sh`).

**Aset diminta:** `og-image.svg` final 1200×630 (pakai ikon `#2563EB`), lalu
raster ulang `og-image.png` dari SVG itu.

## 4. Product Image Placeholder — **Wajib (jika foto produk dipakai lagi)** ⬜ belum berubah

`public/images/product-placeholder.svg` — 400×400, ilustrasi kotak abu
(gambar "foto produk"). Direferensikan dari `resources/js/Utils/imageUrl.js`
(`getProductImageUrl()`), namun **kedua consumer utamanya sudah dilepas**:
grid POS (`ProductGrid.jsx`) dan tabel `Pages/Dashboard/Products/Index.jsx`
(keduanya sudah di-commit: `86c1978`) tidak lagi menampilkan area gambar produk.

- Jika foto produk tidak akan ditampilkan: `imageUrl.js` + file ini bisa dihapus.
- Jika akan dipakai lagi: siapkan `product-placeholder.svg` baru 1:1.

Diverifikasi 18 Okt 2026: `public/images/product-placeholder.svg` dan
`resources/js/Utils/imageUrl.js` masih ada apa adanya (belum diubah atau dihapus).

## 5. Category Image Placeholder — **Opsional** ⬜ belum berubah

`Pages/Dashboard/Categories/Index.jsx` — card grid menampilkan area gambar
`aspect-[3/2]` dengan fallback `IconCategory` 48px bila kategori belum punya
gambar. Halaman Create/Edit memakai `IconPhoto` untuk area upload.

**Aset diminta (opsional):** `public/images/category-placeholder.svg` — rasio 3:2.

Diverifikasi 18 Okt 2026: file belum ada; `Categories/Index.jsx` masih memakai
`aspect-[3/2]` + fallback `IconCategory` dan `IconPhoto` seperti semula.

## 6. Empty-State Illustrations — **Opsional (polish)** ⬜ belum berubah

25+ halaman dashboard memakai glyph tabler `IconDatabaseOff` (24–28px) sebagai
empty state, mis. `Products/Index`, `Customers/Index`, `Transactions/History`,
`Reports/{Sales,Profit,Insights}`, `Roles/Index`, `Permissions/Index`,
`Suppliers/Index`, `CustomerVouchers/Index`, `Members/Show`, `Customers/Show`.
Dashboard (`Pages/Dashboard/Index.jsx`) memakai `IconBox` / `IconPackageOff`.

Ini bukan placeholder file — hanya ikon generik. Jika ingin ilustrasi khusus,
siapkan mis. `public/images/empty-{products,transactions,reports,customers}.svg`
(~160×120) lalu saya ganti per konteks.

Diverifikasi 18 Okt 2026: tidak ada satu pun file `public/images/empty-*.svg`
(0 file); halaman masih memakai glyph tabler seperti sebelumnya.

---

## Ringkasan terverifikasi (18 Okt 2026)

| # | File baru / isi ulang | Ukuran | Prioritas | Status | Catatan verifikasi |
|---|---|---|---|---|---|
| 1 | ~~`public/images/brand-mark.svg`~~ (dipakai `icon.svg`) | 512×512 square | Wajib | ✅ selesai | `BRAND.mark` + `<img>` di `BrandLogo.jsx` terpasang |
| 2 | `public/images/icon.svg` (+192/512 png, favicon.ico) | 512×512 | Wajib | ✅ selesai | 4 file ada & terkirim ke package |
| 3 | `public/images/og-image.svg` (+ raster `og-image.png`) | 1200×630 | Wajib | ⬜ belum berubah | Desain & mark masih lama; PNG lebih tua dari SVG |
| 4 | `public/images/product-placeholder.svg` | 400×400 (rasio 1:1) | Wajib* | ⬜ belum berubah | File & `imageUrl.js` masih ada apa adanya |
| 5 | `public/images/category-placeholder.svg` | rasio 3:2 | Opsional | ⬜ belum berubah | File belum ada; fallback `IconCategory` |
| 6 | `public/images/empty-*.svg` | ~160×120 | Opsional | ⬜ belum berubah | 0 file; masih glyph tabler |

\* Hentikan jika foto produk tidak ditampilkan lagi di UI.

### Yang belum berubah sejak audit awal

- **Item 3 (OG image)** — masih placeholder, dan raster `og-image.png` justru
  versi pra-rebrand (lebih tua dari SVG-nya). Satu-satunya item **Wajib** yang
  benar-benar menggantung, sekaligus yang berdampak publik karena
  `resources/views/app.blade.php` menunjuk `og:image`/`twitter:image` ke PNG ini.
- **Item 4 (product placeholder)** — belum diputuskan: dihapus atau diganti.
- **Item 5 (category placeholder)** — aset belum dibuat (opsional).
- **Item 6 (empty-state illustration)** — aset belum dibuat (opsional).

### Sudah dipasang

- fallback `BrandLogo.jsx` → `<img src={BRAND.mark}>` (huruf "K" dihapus)
- raster ikon aplikasi (icon-192/512, favicon.ico) dari `icon.svg`

### Sisa pekerjaan aset

- Item 3: `og-image.svg` final (ikon `#2563EB`) + raster ulang `og-image.png`.
- Opsional: `scripts/build-brand-assets.sh` untuk otomatisasi rasterisasi.
