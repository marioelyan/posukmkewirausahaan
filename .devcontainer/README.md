# Dev Container

Lingkungan dev lengkap untuk Dikasir POS: **PHP 8.3 + Composer**, **Node 24**, dan **MySQL 8**.

- `devcontainer.json` - definisi environment (extensions, ports, post-create).
- `docker-compose.yml` - service `app` (PHP) + `db` (MySQL 8, db `point_of_sales`).
- `Dockerfile` - base image PHP 8.3 + extension yang dibutuhkan.
- `setup.sh` - dijalankan otomatis sekali saat container dibuat.

## Pakai via GitHub Codespaces

1. Repo -> **Code ▾** -> tab **Codespaces** -> **Create codespace on main**.
2. Tunggu build + post-create (composer/npm install, migrate, seed).
3. Buka tab **Ports** -> port **8000** (atau jalankan `composer run dev` di terminal).

## Pakai di VS Code lokal (Docker)

```bash
# butuh Docker + ekstensi Dev Containers
Dev Containers: Reopen in Container
```

## Perintah harian

```bash
composer run dev     # server + queue + logs + Vite
npm run build        # build asset produksi
php artisan test     # jalankan test (sqlite in-memory)
```

Kredensial MySQL dev: db `point_of_sales`, user `pos`, password `secret` (host `db`).
