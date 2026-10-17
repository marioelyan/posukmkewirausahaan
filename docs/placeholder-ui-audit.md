# Audit UI Placeholder — Aset SVG yang Perlu Disiapkan

Audit permukaan UI yang masih menampilkan grafis placeholder (bukan aset desain final).
Daftar ini disusun agar aset SVG final bisa disiapkan dan tinggal ditukar.

Legenda prioritas:

- **Wajib** — placeholder terlihat jelas di UI publik/aplikasi.
- **Opsional** — hanya muncul sebagai fallback jarang / polish.

---

## 1. Brand Mark (huruf "K" gradient) — **Wajib**

Kotak gradient navy dengan glyph huruf **"K"** dari Tabler (`IconLetterK`) adalah
placeholder resmi brand. Kode menandainya eksplisit: *"kotak mark platform
(placeholder, mudah ditukar nanti)"* — `resources/js/Components/BrandLogo.jsx`.

**Fallback aktif saat:** store profile belum upload logo (default instalasi baru).

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

**Aset diminta:**

- `public/images/brand-mark.svg` — square, viewBox `0 0 512 512`, bentuk sudah
  membulat di dalam SVG (render di kotak `rounded-xl/2xl` ukuran 32–72px).
- Gaya sekarang: gradient `#456FAF → #1B3559`, glyph putih.
- Implementasi: ganti fallback `IconLetterK` di `BrandLogo.jsx` dengan `<img src="/images/brand-mark.svg">`.

## 2. Ikon Aplikasi / PWA / Favicon — **Wajib**

Saat ini semua berisi glyph "K" yang sama (`public/images/icon.svg`):

| File | Spesifikasi |
|---|---|
| `public/images/icon.svg` | 512×512, dipakai sebagai favicon modern (`type="image/svg+xml"`) |
| `public/images/icon-192.png` | 192×192 — apple-touch-icon + manifest |
| `public/images/icon-512.png` | 512×512 — manifest PWA |
| `public/favicon.ico` | fallback browser lama |

**Aset diminta:** `icon.svg` baru (isi ulang file yang sama); PNG & ICO bisa
dibenerasi dari SVG tersebut (atau saya generate otomatis).

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
(WIP, belum di-commit) tidak lagi menampilkan area gambar produk.

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

| # | File baru / isi ulang | Ukuran | Prioritas |
|---|---|---|---|
| 1 | `public/images/brand-mark.svg` | 512×512 square | Wajib |
| 2 | `public/images/icon.svg` | 512×512 | Wajib |
| 3 | `public/images/og-image.svg` | 1200×630 | Wajib |
| 4 | `public/images/product-placeholder.svg` | 400×400 (rasio 1:1) | Wajib* |
| 5 | `public/images/category-placeholder.svg` | rasio 3:2 | Opsional |
| 6 | `public/images/empty-*.svg` | ~160×120 | Opsional |

\* Hentikan jika foto produk tidak ditampilkan lagi di UI.

Setelah file ditaruh di `public/images/`, saya yang pasang:
- fallback `BrandLogo.jsx` → `<img>`
- raster PNG (icon-192/512, og-image.png, favicon.ico) dari SVG
  (bisa ditambahkan script `scripts/build-brand-assets.sh`).
