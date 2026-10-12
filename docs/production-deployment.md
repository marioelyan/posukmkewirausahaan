# Production Deployment

Panduan menyiapkan dan menjalankan Point of Sales di produksi. Kembali ke indeks: `docs/README.md`.

## 1. Prasyarat Runtime

| Komponen | Versi | Catatan |
|----------|-------|---------|
| PHP | **8.4** | `php8.4 artisan ...` dipakai di VPS dan CI |
| Node.js | 22 (build CI) / **24.15** (VPS) | `PUPPETEER_SKIP_DOWNLOAD=true` untuk semua `npm ci`/`npm run build` |
| npm | sesuai package-lock | npm adalah package manager resmi (`package-lock.json` dikomit) |
| Composer | 2.x | |
| MySQL | 8.x | DB produksi (strict mode) |
| Cron | — | wajib untuk scheduler (`schedule:run` tiap menit) |
| Supervisor / systemd / PM2 | — | queue worker + WhatsApp service |

## 2. Dua Jalur Deploy

### 2a. VPS — otomatis via GitHub Actions (default)

`.github/workflows/deploy.yml` menjalankan **CI** (validate Composer, `npm run build`,
`php artisan test --compact` di PHP 8.4 / Node 22 / SQLite in-memory) pada setiap push/PR,
lalu **deploy otomatis ke `main`** via SSH.

**Secrets GitHub yang harus diset** (repo → Settings → Secrets and variables → Actions):

| Secret | Isi |
|--------|-----|
| `SSH_HOST` | Host VPS (mis. `dikasir.web.id`) |
| `SSH_USERNAME` | User SSH deploy |
| `SSH_PRIVATE_KEY` | Private key SSH (tanpa passphrase) |
| `DEPLOY_PATH` | Path absolut checkout aplikasi di VPS |
| `SSH_PORT` (opsional) | Port SSH, default `22` |

Skrip deploy di VPS: `git pull --ff-only` → `composer install --no-dev` → `npm ci` +
`npm run build` → `php8.4 artisan migrate --force` → `storage:link` →
`config:cache` + `view:cache` + `scramble:cache` → `queue:restart`. `route:cache`
sengaja **tidak** dijalankan (ada route closure; `route:cache` akan gagal).

> VPS harus punya Node 24.15 + PHP 8.4 di PATH sesuai AGENTS.md. Jika version manager
> (nvm) dipakai, pastikan `nvm use default` aktif di shell non-interaktif SSH.

### 2b. Shared hosting — package manual

`./scripts/deploy-build.sh` membuat `deploy/deploy-package-YYYYMMDD.zip` (staging
tervalidasi; tidak mengandung `.env`, node_modules, tests, symlink). Langkah manual:

1. Buat `.env` produksi **terpisah** (jangan dari repo, jangan masuk ZIP).
2. Upload + ekstrak isi ZIP via File Manager/FTP.
3. Pastikan `storage/` dan `bootstrap/cache/` writable.
4. Jalankan via Cron/terminal: `php artisan migrate --force` lalu `php artisan storage:link`.

## 3. Environment Checklist Produksi

Salin `.env.example`, isi, lalu set nilai produksi:

| Variable | Nilai produksi | Alasan |
|----------|----------------|--------|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | Jangan bocorkan stack trace |
| `APP_URL` | `https://dikasir.web.id` | Webhook Midtrans/Xendit, invoice publik, portal pelanggan |
| `APP_VERSION` | `vX.Y.Z` | Bump saat release (lihat AGENTS.md) |
| `SESSION_SECURE_COOKIE` | `true` | Cookie sesi hanya via HTTPS |
| `SANCTUM_STATEFUL_DOMAINS` | `dikasir.web.id` | Hapus entri `localhost` di produksi |
| `SCRAMBLE_DOCS_TOKEN` | token acak | Mengunci `/docs/api` & `/docs/api.json` (kirim via header `X-Docs-Token` atau `?token=`) |
| `MULTI_BUSINESS_ENABLED` | `false` | **Jangan** aktifkan sampai backfill tenant + membership selesai |
| `WA_SERVICE_URL` / `WA_SERVICE_TOKEN` | URL + secret | Harus cocok dengan `.env` service WhatsApp |
| `MIDTRANS_IS_PRODUCTION` / `XENDIT_IS_PRODUCTION` | `true` | Aktifkan mode produksi gateway |
| `XENDIT_CALLBACK_TOKEN` | token webhook | Verifikasi callback Xendit |
| `AUTH_PUBLIC_REGISTRATION` | `false` (default) | Registrasi publik dimatikan |

Setelah deploy pertama: buka root URL → instalasi pertama otomatis mengarahkan ke
`/setup` (buat admin, profil toko, kategori, warehouse utama). Setelah selesai, arahkan
ke login. Lihat `docs/getting-started.md`.

## 4. Scheduler (wajib)

Tambahkan ke crontab (JALAN SETIAP MENIT):

```
* * * * * cd /path/aplikasi && php8.4 artisan schedule:run >> /dev/null 2>&1
```

Jadwal terdaftar di `routes/console.php`:

| Jam | Perintah | Fungsi |
|-----|----------|--------|
| 01:00 | `crm:sync-segments` | Refresh membership segmen otomatis |
| 01:15 | `crm:generate-reminders` | Antre pesan reminder campaign |
| 02:00 | `reorder:generate` | Draft PO dari stok menipis |
| setiap jam | `transactions:expire` | Kedaluwarsa transaksi hold/offline |

## 5. Queue Worker + Failed Jobs

`QUEUE_CONNECTION=database` (default). Jalankan worker dengan supervisor/systemd:

```
php8.4 artisan queue:work database --sleep=3 --tries=3 --max-time=3600
```

Product key: `QUEUE_FAILED_DRIVER=database` agar job gagal tersimpan di `failed_jobs`.

Contoh systemd unit `pos-queue.service`:

```ini
[Unit]
Description=POS Queued Jobs
After=network.target

[Service]
User=www-data
WorkingDirectory=/path/aplikasi
ExecStart=/usr/bin/php8.4 artisan queue:work database --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

Setelah deploy, `php8.4 artisan queue:restart` (sudah ada di workflow) membuat worker
memuat kode terbaru.

## 6. WhatsApp Service (opsional)

`whatsapp-service/` adalah service Node terpisah (port 3001). Jalankan via PM2:

```bash
cd whatsapp-service
npm install
pm2 start server.js --name wa-service
pm2 save && pm2 startup
```

Pastikan `WA_SERVICE_TOKEN` cocok antara `.env` aplikasi dan service. CRM auto-send
membutuhkan `wa_enabled=true` + perangkat tersambung (Settings → WhatsApp).

## 7. Pasca-Deploy / Perawatan

```bash
php8.4 artisan about                 # status aplikasi
php8.4 artisan scramble:cache        # warm cache OpenAPI (sudah di workflow)
php8.4 artisan inventory:reconcile   # cek selisih stok global vs pivot (read-only)
php8.4 artisan inventory:reconcile --fix  # sejajarkan (hanya setelah audit)
```

## 8. Backup & Rollback

- **DB:** `mysqldump -u user -p point_of_sales > backup-$(date +%F).sql`
- **File:** idempotent `storage/app` (logo, import, export) — simpan bersama backup DB.
- **Rollback release:** deploy kembali tag/revisi sebelumnya (VPS: `git checkout <tag>`;
  shared hosting: ekstrak package sebelumnya). Jangan lupa `config:cache` + `view:cache`
  ulang.
- **Migrasi:** selalu `migrate --force` di produksi; uji dulu di staging/CI.

## 9. Keamanan Singkat

- HTTPS wajib (certbot/cloudflare). `SESSION_SECURE_COOKIE=true` + `SESSION_SAME_SITE=lax`.
- `APP_DEBUG=false`, `LOG_LEVEL=warning` di produksi bila perlu.
- `SCRAMBLE_DOCS_TOKEN` diisi agar dokumentasi API tidak terbuka publik.
- Rate limiter API 120 req/menit per user/IP (`app/Providers/AppServiceProvider.php`).
- Bot guard (`SECURITY_BOT_GUARD_*`) + `SECURITY_SESSION_ABSOLUTE_LIFETIME_SECONDS=43200`
  (12 jam) + `AUTH_PASSWORD_TIMEOUT=900` (15 menit) aktif di config `security.php`.
- Jangan pernah mengirim `.env`, `APP_KEY` (base64:), atau credential gateway lewat chat/ZIP.