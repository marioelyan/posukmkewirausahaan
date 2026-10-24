# Production Deployment

Panduan menyiapkan dan menjalankan KasirKwu di produksi. Kembali ke indeks: `docs/README.md`.

> **Ringkas.** Produksi = **shared hosting DewaBiz** (panel DirectAdmin Evo), `posukmkewirausahaan.web.id`,
> **tanpa SSH/terminal**. Deploy = **manual per rilis** (build package → upload → artisan via cron).
> Tidak ada pipeline auto-deploy. Workflow SSH lama sudah dihapus dari repo.

## 1. Prasyarat Runtime

| Komponen | Versi | Catatan |
|----------|-------|---------|
| PHP | **8.4** | Dipilih dari panel DewaBiz; versi yang sama dipakai CI |
| Node.js | 22 (laptop/CI saja) | **Tidak ada di server** — aset frontend dibangun sebelum upload |
| npm | sesuai package-lock | npm adalah package manager resmi (`package-lock.json` dikomit) |
| Composer | 2.x | **Hanya di laptop/CI** — server tidak punya Composer, jadi `vendor/` ikut di package |
| MySQL | 8.x | DB produksi (strict mode), dibuat dari panel |
| Cron | — | panel DewaBiz; wajib untuk scheduler `schedule:run` tiap menit |
| Queue worker / Supervisor / systemd / PM2 | — | **Tidak tersedia di shared hosting** — lihat §5–§6 |

`PUPPETEER_SKIP_DOWNLOAD=true` wajib di semua `npm ci`/`npm run build` (di laptop maupun CI).

## 2. Jalur Deploy — manual via panel DewaBiz

`.github/workflows/ci.yml` **hanya** menjalankan CI (validate Composer, `npm run build`,
`php artisan test --compact` di PHP 8.4 / Node 22 / SQLite in-memory) pada setiap push/PR.
**Tidak ada job deploy.** Workflow lama (`appleboy/ssh-action`, SSH ke VPS `dikasir.web.id`)
sudah dihapus: shared hosting tidak punya SSH, jadi workflow itu tidak mungkin jalan.

Setiap rilis = satu package manual, **dua ZIP** (terverifikasi 2026-10-24):

### Struktur server (baca dulu — ini yang paling sering salah)

```
domains/posukmkewirausahaan.web.id/
├── posapp/       # kode aplikasi (app/, vendor/, bootstrap/, storage/, ...)
└── public_html/  # DOCUMENT ROOT — direktori ASLI terpisah, BUKAN symlink
```

- PHP dijalankan dari `posapp/`, tapi file statis dilayani dari `public_html/`.
  **Ekstrak package ke `posapp/` saja TIDAK memperbarui aset web** — HTML baru akan
  merujuk aset `build/` baru yang belum ada di `public_html/` (404), jadi rilis
  selalu butuh langkah sinkronisasi §2 langkah 4.
- `public_html/index.php` adalah bootstrap **CUSTOM** (482 B): me-`require`
  `../posapp/vendor/autoload.php` dan `../posapp/bootstrap/app.php`.
  **Jangan pernah ditimpa** `public/index.php` bawaan Laravel.
- `public_html/` juga berisi file yang **tidak boleh** ikut tertimpa sync:
  `.htaccess`, `favicon.ico`, `manifest.json` (PWA), `robots.txt`, `sw.js`,
  symlink `storage` → `../posapp/storage/app/public`, dan `index.php` custom di atas.
  Sync hanya menyentuh `build/`, `images/`, `inter/`, `screenshots/`.

### Langkah deploy

1. **Build package (di laptop).** `./scripts/deploy-build.sh` menghasilkan DUA file
   di root repo: `deploy-package-YYYYMMDD.zip` (`composer install --no-dev`, jadi
   `vendor/` ikut; `npm ci` + `npm run build` → `public/build`; tanpa `.env`,
   `node_modules`, tests, atau symlink) **dan** `public-sync-YYYYMMDD.zip` (isi
   `public/build`, `public/images`, `public/inter`, `public/screenshots` dari staging).
   Script menormalisasi mode tersimpan jadi file `0644` / folder `0755` sebelum zip
   (lihat langkah 5 soal kenapa ini wajib) dan menolak menimpa file dengan tanggal
   sama — hapus manual kalau mau rebuild.
2. **Backup dulu.** Backup **full account** dari panel DirectAdmin (DB + semua file,
   termasuk `storage/app`) sebelum upload. Alternatif minimal: export DB (phpMyAdmin)
   + copy `storage/app`. Simpan nama file backup di release notes.
3. **Upload + ekstrak package utama.** Isi `deploy-package-YYYYMMDD.zip` ke
   `posapp/` via **File Manager** (Extract → destination `posapp/`, centang
   "Merge and overwrite"). Ini menimpa file lama, tapi **tidak menghapus** file yang
   sudah dihapus di repo — controller/route/view yang dipensiunkan harus dibersihkan
   manual. **`.env` server tidak pernah ikut ZIP dan tidak boleh tertimpa** —
   kredensial DB dan `APP_KEY` produksi hanya ada di sana.
4. **Upload + ekstrak sync ZIP.** Isi `public-sync-YYYYMMDD.zip` ke `public_html/`
   via File Manager (Extract → destination `public_html/`, "Merge and overwrite").
   Langkah ini yang memperbarui aset `build/` (JS/CSS + `manifest.json` Vite),
   `images/`, `inter/`, `screenshots/` yang dilayani nginx.
5. **Permission — wajib 0644/0755.** File di VM/laptop pengembang umumnya bermode
   `0660`/`0770` (umask). Ekstraktor DirectAdmin **mempertahankan mode dari ZIP**,
   dan nginx berjalan sebagai user berbeda dari pemilik file → file `0660` dibalas
   **403** untuk semua aset baru, padahal halaman login tetap 200 (PHP-FPM jalan
   sebagai user akun, jadi kode di `posapp/` tidak terpengaruh). Script build sudah
   menormalisasi mode sebelum zip; kalau aset baru tetap 403, cek mode file di
   File Manager dulu sebelum mengutak-atik hal lain. `storage/` dan
   `bootstrap/cache/` tetap harus writable oleh user akun.
6. **Jalankan artisan** — tidak ada terminal, jadi lewat **Cron panel sekali-jalan**
   (jadwal satu menit ke depan, tanggal/bulan spesifik, lalu hapus cron-nya).
   Rantai perintah memakai pemisah `;` (bukan `&&`) supaya semua langkah tetap jalan
   walau satu gagal, dan log ditulis ke file (`>> ~/migrasi-pos.log 2>&1`):
   `migrate --force` → `storage:link` → `view:cache` → `scramble:cache`.
   **`config:cache` sengaja TIDAK dijalankan** — konfigurasi Scramble berisi objek
   security scheme yang tidak bisa diserialisasi (`scramble.security_strategy.1.scheme
   is non-serializable`), jadi perintah itu selalu gagal. Gagalnya aman (tidak
   menulis cache) dan aplikasi jalan dari config live; tidak ada `bootstrap/cache/
   config.php` yang basi. `route:cache` juga tidak dijalankan (ada route closure;
   pasti gagal).
7. **Smoke test dari luar** (jangan cuma buka halaman):
   `curl` aset JS/CSS baru (harus 200, bukan 403/404), `/build/manifest.json` harus
   menunjuk hash file baru, `/images/og-image.png` 200, dan halaman login 200.
   Catatan: apex `posukmkewirausahaan.web.id` me-redirect 301 (nginx) ke `www`,
   jadi cek final di `www`. Untuk perubahan perilaku, cek juga
   `docs/testing-manual.md`, `docs/feature-index.md`, dan release notes.
8. **Cleanup.** Hapus semua ZIP dari server (`posapp/deploy-package-*.zip`,
   `public_html/public-sync-*.zip`) dan **hapus cron sekali-jalan**; verifikasi daftar
   cron kembali ke yang permanen saja (schedule POS tiap menit, backup DB, schedule
   presensi tiap menit).
9. **Versi yang tampil ≠ versi kode.** Label versi di aplikasi berasal dari
   `APP_VERSION` di **`.env` server**, bukan dari package (package tidak berisi
   `.env`). Jadi setelah deploy, label bisa tetap menunjukkan versi lama — itu
   normal. Naikkan `APP_VERSION` di `.env` server (edit via File Manager, hanya baris
   itu) sebagai langkah rilis tersendiri, bersamaan dengan bump `.env.example` di
   repo saat tagging (lihat AGENTS.md).
10. **Selalu catat perubahan yang mengubah angka** (mis. definisi omzet `completed`
    = `paid`) di release notes sebelum deploy.

### Catatan panel File Manager (DirectAdmin Evo)

- Tombol **Extract** di toolbar hanya muncul bila **tepat satu** arsip terpilih.
  Dua file terpilih mengubah toolbar (Copy/Move/Archive/Set Permissions/Remove).
- Mengetik angka oktal di dialog Set Permissions tidak andal — pakai baris preset
  ("Shared directory (755)") untuk 0755.
- Sebagian tombol dialog (mis. START UPLOAD, tombol REMOVE tertentu) kadang
  mengabaikan klik otomatis — kalau dialog tidak merespons, klik manual tombolnya,
  lalu verifikasi lewat screenshot/refresh listing.

### Checklist per rilis

```
[ ] php artisan test --compact                 # di laptop, hijau dulu
[ ] vendor/bin/pint                            # formatting
[ ] tag rilis vX.Y.Z dari branch development   # + bump APP_VERSION (.env.example)
[ ] ./scripts/deploy-build.sh                  # -> deploy-package-YYYYMMDD.zip + public-sync-YYYYMMDD.zip (mode 0644/0755)
[ ] backup full account via panel (DB + semua file)
[ ] upload + extract package utama ke posapp/ (Merge and overwrite; .env JANGAN tertimpa)
[ ] upload + extract public-sync-*.zip ke public_html/ (Merge and overwrite; index.php custom JANGAN tertimpa)
[ ] pastikan storage/ & bootstrap/cache/ writable
[ ] cron sekali-jalan: migrate --force; storage:link; view:cache; scramble:cache (TANPA config:cache)
[ ] smoke test: JS/CSS baru 200, manifest hash baru, og-image 200, login 200 (cek di www)
[ ] hapus cron sekali-jalan + hapus semua ZIP dari server
[ ] bump APP_VERSION di .env server (langkah rilis tersendiri)
[ ] rapi-kan file yang sudah dipensiunkan (extract tidak menghapusnya)
```

> **Kalau nanti pindah ke host bershell (VPS):** jalur otomatis baru masuk akal —
> `git pull --ff-only` + artisan via SSH, atau (tetap di shared hosting) CI build →
> upload FTP → cron worker ber-flag. Belum dipakai; keputusan 2026-10-13: manual dulu.

## 3. Environment Checklist Produksi

Salin `.env.example`, isi, lalu set nilai produksi. `.env` produksi **dibuat di server**,
bukan dari repo, dan tidak boleh ikut masuk ZIP.

| Variable | Nilai produksi | Alasan |
|----------|----------------|--------|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | Jangan bocorkan stack trace |
| `APP_URL` | `https://posukmkewirausahaan.web.id` | Webhook Midtrans/Xendit, invoice publik, portal pelanggan |
| `APP_VERSION` | `vX.Y.Z` | Bump saat release (lihat AGENTS.md) |
| `SESSION_SECURE_COOKIE` | `true` | Cookie sesi hanya via HTTPS |
| `SANCTUM_STATEFUL_DOMAINS` | `posukmkewirausahaan.web.id` | Hapus entri `localhost` di produksi |
| `DB_HOST` | `localhost` | Socket lokal; `127.0.0.1` ditolak (access denied) |
| `SCRAMBLE_DOCS_TOKEN` | token acak | Mengunci `/docs/api` & `/docs/api.json` (header `X-Docs-Token` atau `?token=`) |
| `MULTI_BUSINESS_ENABLED` | `true` saat go-live | Default repo `false`. Keputusan owner 2026-10-13: jalur **fresh install** (tanpa backfill legacy) — lihat `docs/architecture/multi-business-backfill-production-runbook.md` |
| `WA_SERVICE_URL` / `WA_SERVICE_TOKEN` | URL + secret | Harus cocok dengan `.env` service WhatsApp (kalau service-nya jalan) |
| `MIDTRANS_IS_PRODUCTION` / `XENDIT_IS_PRODUCTION` | `true` | Aktifkan mode produksi gateway |
| `XENDIT_CALLBACK_TOKEN` | token webhook | Verifikasi callback Xendit |
| `AUTH_PUBLIC_REGISTRATION` | `false` (default) | Registrasi publik dimatikan |

Setelah deploy ke instalasi kosong: buka root URL → otomatis mengarah ke
`/setup` (buat admin, profil toko, kategori, warehouse utama, dan business default saat
flag tenancy ON). Setelah selesai, arahkan ke login. Lihat `docs/getting-started.md`.

## 4. Scheduler (wajib)

Tambahkan lewat **Cron panel DewaBiz** (JALAN SETIAP MENIT):

```
* * * * * cd /path/aplikasi && php artisan schedule:run >> /dev/null 2>&1
```

Jadwal terdaftar di `routes/console.php`:

| Jam | Perintah | Fungsi |
|-----|----------|--------|
| 01:00 | `crm:sync-segments` | Refresh membership segmen otomatis |
| 01:15 | `crm:generate-reminders` | Antre pesan reminder campaign |
| 02:00 | `reorder:generate` | Draft PO dari stok menipis |
| setiap jam | `transactions:expire` | Kedaluwarsa transaksi hold/offline |

Command scheduler dijalankan per-business lewat `BusinessTaskRunner` saat tenancy ON
(enumerate business aktif → set context → clear di `finally`).

## 5. Queue Worker

`QUEUE_CONNECTION=database` (default) dan aplikasi dirancang **sinkron/tanpa worker**
di shared hosting (keputusan SCHED-01/02). Karena itu:

- **Shared hosting: tidak ada queue worker.** Job yang butuh eksekusi harus sinkron;
  scheduler ditangani cron §4.
- **Kalau nanti pindah ke VPS** (supervisor/systemd):

```
php artisan queue:work database --sleep=3 --tries=3 --max-time=3600
```

`QUEUE_FAILED_DRIVER=database` agar job gagal tersimpan di `failed_jobs`.

## 6. WhatsApp Service

`whatsapp-service/` adalah service Node terpisah (port 3001) yang **tidak bisa jalan di
shared hosting** (butuh proses long-running + Node). Di produksi sekarang service ini
tidak aktif; fitur CRM auto-send baru jalan kalau service-nya di-host terpisah (VPS).

```bash
cd whatsapp-service
npm install
pm2 start server.js --name wa-service
pm2 save && pm2 startup
```

## 7. Pasca-Deploy / Perawatan

Lewat cron sekali-jalan (tidak ada terminal):

```bash
php artisan about                 # status aplikasi
php artisan scramble:cache        # warm cache OpenAPI
php artisan inventory:reconcile   # cek selisih stok global vs pivot (read-only)
php artisan inventory:reconcile --fix  # sejajarkan (hanya setelah audit)
```

## 8. Backup & Rollback

- **DB:** export dari phpMyAdmin (atau `mysqldump` kalau disediakan host): `backup-YYYY-MM-DD.sql`
- **File:** `storage/app` (logo, import, export) — simpan bersama backup DB.
- **Rollback:** ekstrak ulang package/tag sebelumnya (ke `posapp/` + sync ZIP ke
  `public_html/`), lalu `view:cache` + `scramble:cache` ulang. `config:cache` tetap
  tidak dijalankan (alasan yang sama seperti §2 langkah 6).
- **Migrasi:** selalu `migrate --force` di produksi; uji dulu di CI/staging lokal.
  Migrasi tenancy era multi-business bersifat aditif/guarded.

## 9. Keamanan Singkat

- HTTPS wajib. `SESSION_SECURE_COOKIE=true` + `SESSION_SAME_SITE=lax`.
- `APP_DEBUG=false`, `LOG_LEVEL=warning` di produksi bila perlu.
- `SCRAMBLE_DOCS_TOKEN` diisi agar dokumentasi API tidak terbuka publik.
- Rate limiter API 120 req/menit per user/IP (`app/Providers/AppServiceProvider.php`).
- Bot guard (`SECURITY_BOT_GUARD_*`) + `SECURITY_SESSION_ABSOLUTE_LIFETIME_SECONDS=43200`
  (12 jam) + `AUTH_PASSWORD_TIMEOUT=900` (15 menit) aktif di config `security.php`.
- Jangan pernah mengirim `.env`, `APP_KEY` (base64:), atau credential gateway lewat chat/ZIP.
