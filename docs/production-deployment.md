# Production Deployment

Panduan menyiapkan dan menjalankan KasirnyaKWU di produksi. Kembali ke indeks: `docs/README.md`.

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

Setiap rilis = satu package manual:

1. **Build package (di laptop).** `./scripts/deploy-build.sh` menghasilkan
   `deploy-package-YYYYMMDD.zip` di root repo: `composer install --no-dev` (jadi `vendor/` ikut),
   `npm ci` + `npm run build` (`public/build`), tanpa `.env`, `node_modules`, tests, atau symlink.
   Script ini menolak menimpa file dengan tanggal sama — hapus manual kalau mau rebuild.
2. **Backup dulu.** Export DB (phpMyAdmin) + copy `storage/app`.
3. **Upload + ekstrak.** Isi ZIP ke direktori app via **File Manager** atau **FTP** panel.
   Ini menimpa file lama, tapi **tidak menghapus** file yang sudah dihapus di repo —
   controller/route/view yang dipensiunkan harus dibersihkan manual.
4. **Permission:** pastikan `storage/` dan `bootstrap/cache/` writable.
5. **Jalankan artisan** — tidak ada terminal, jadi lewat **Cron panel sekali-jalan**
   (`php artisan <cmd>`, lalu hapus cron-nya):
   `migrate --force` → `storage:link` → `config:cache` → `view:cache` → `scramble:cache`.
   `route:cache` sengaja **tidak** dijalankan (ada route closure; pasti gagal).
6. **Smoke test** — `docs/testing-manual.md`. Untuk perubahan perilaku, cek juga
   `docs/feature-index.md` dan release notes.
7. **Selalu catat perubahan yang mengubah angka** (mis. definisi omzet `completed` = `paid`)
   di release notes sebelum deploy.

### Checklist per rilis

```
[ ] php artisan test --compact                 # di laptop, hijau dulu
[ ] vendor/bin/pint                            # formatting
[ ] tag rilis vX.Y.Z dari branch development   # + bump APP_VERSION
[ ] ./scripts/deploy-build.sh                  # -> deploy-package-YYYYMMDD.zip
[ ] backup DB + storage/app
[ ] upload + extract ZIP via panel (File Manager / FTP)
[ ] pastikan storage/ & bootstrap/cache/ writable
[ ] cron sekali-jalan: migrate --force, storage:link, config:cache, view:cache, scramble:cache
[ ] smoke test docs/testing-manual.md
[ ] hapus cron sekali-jalan
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
- **Rollback:** ekstrak ulang package/tag sebelumnya, lalu `config:cache` + `view:cache` ulang.
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
