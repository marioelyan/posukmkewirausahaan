#!/usr/bin/env bash
#
# deploy-build.sh — Production build + deployment package untuk shared hosting.
# Dijalankan DI LAPTOP, bukan di server. Tidak melakukan upload, migration,
# storage:link, optimize, atau pembuatan .env produksi.
#
# Pemakaian:
#   ./scripts/deploy-build.sh
#
# Hasil:
#   deploy/                      staging directory
#   deploy-package-YYYYMMDD.zip  package utama -> ekstrak ke posapp/ di server
#   public-sync-YYYYMMDD.zip     aset public/ -> ekstrak ke public_html/ di server
#                                (public_html adalah docroot terpisah, bukan symlink;
#                                lihat docs/production-deployment.md §2)
#
# Syarat: PHP 8.3 + Composer 2, Node 18+ / npm, zip, rsync.

set -euo pipefail
cd "$(dirname "$0")/.."

STAGING="deploy"
OUT="deploy-package-$(date +%Y%m%d).zip"
SYNC_OUT="public-sync-$(date +%Y%m%d).zip"

fail() { echo "GAGAL: $1" >&2; exit 1; }

if [ -e "$OUT" ]; then
    fail "$OUT sudah ada. Hapus manual dulu jika ingin rebuild (package lama tidak ditimpa otomatis)."
fi
if [ -e "$SYNC_OUT" ]; then
    fail "$SYNC_OUT sudah ada. Hapus manual dulu jika ingin rebuild (package lama tidak ditimpa otomatis)."
fi

echo "==> [1/7] Composer production install"
composer install --no-dev --optimize-autoloader --no-interaction
[ -f vendor/autoload.php ] || fail "vendor/autoload.php tidak ada"

echo "==> [2/7] npm ci (berbasis package-lock, tanpa unduh Chromium)"
PUPPETEER_SKIP_DOWNLOAD=true npm ci

echo "==> [3/7] Vite production build"
rm -rf public/build
npm run build
[ -s public/build/manifest.json ] || fail "public/build/manifest.json kosong/tidak ada"
[ ! -e public/hot ] || fail "public/hot tidak boleh ada setelah build"
[ ! -e bootstrap/ssr ] || fail "SSR bundle tidak boleh terbentuk (SSR nonaktif di shared hosting)"

echo "==> [4/7] Laravel boot test (env lokal)"
php artisan about > /dev/null || fail "php artisan about error"

echo "==> [5/7] Staging ke ${STAGING}/"
rm -rf "$STAGING"
mkdir -p "$STAGING"
# Catatan: setiap pola di bawah menjaga file .gitignore placeholder (rsync
# tidak menganggap '*' cocok dengan nama berawalan titik), tapi membuang data
# runtime lokal: DB dev, file contoh, upload, cache, sesi, font dompdf, log.
rsync -a \
    --exclude='public/hot' \
    --exclude='public/storage' \
    --exclude='database/*.sqlite' \
    --exclude='database/samples/' \
    --exclude='storage/app/public/*' \
    --exclude='storage/fonts/*' \
    --exclude='storage/framework/views/*.php' \
    --exclude='storage/framework/cache/*' \
    --exclude='storage/framework/sessions/*' \
    --exclude='storage/framework/testing/*' \
    --exclude='storage/logs/*.log' \
    --exclude='storage/pail/*.pail' \
    --exclude='bootstrap/cache/*.php' \
    app bootstrap config database lang public resources routes storage vendor "$STAGING/"
cp artisan composer.json composer.lock "$STAGING/"

echo "==> [6/7] Validasi staging"
for f in artisan composer.json composer.lock vendor/autoload.php \
         public/index.php public/build/manifest.json public/.htaccess; do
    [ -e "$STAGING/$f" ] || fail "$f tidak ada di staging"
done
for d in app bootstrap config database lang public resources routes storage vendor; do
    [ -d "$STAGING/$d" ] || fail "folder $d/ tidak ada di staging"
done
for bad in .env .env.example .env.production node_modules .git public/hot \
           public/storage whatsapp-service tests; do
    [ ! -e "$STAGING/$bad" ] || fail "$bad tidak boleh masuk package"
done
[ -z "$(find "$STAGING/storage" -type f ! -name .gitignore)" ] || fail "ada artefak lokal di storage/"
[ -z "$(find "$STAGING/bootstrap/cache" -type f ! -name .gitignore)" ] || fail "ada cache lokal di bootstrap/cache/"
[ -z "$(find "$STAGING/database" -maxdepth 1 -name '*.sqlite*' -print -quit)" ] || fail "ada file sqlite lokal di database/ (jangan pernah dikirim ke produksi)"
[ ! -e "$STAGING/database/samples" ] || fail "database/samples/ (data contoh lokal) tidak boleh masuk package"
[ -z "$(find "$STAGING" -type l)" ] || fail "ada symlink di staging (public/storage laptop tidak boleh ikut)"
if grep -rIl 'APP_KEY=base64:' "$STAGING" > /dev/null 2>&1; then
    fail "ditemukan APP_KEY terisi di staging"
fi

echo "==> [7/7] Normalisasi izin + membuat ${OUT} dan ${SYNC_OUT}"
# PENTING (insiden deploy 2026-10-24): file di VM/laptop pengembang umumnya
# bermode 0660/0770 (umask). Ekstraktor DirectAdmin mempertahankan mode dari ZIP,
# dan nginx (user berbeda dari pemilik file) membalas file 0660 dengan 403 untuk
# semua aset baru. Normalisasi ke 0644/0755 SEBELUM zip agar package langsung
# bisa dilayani nginx. PHP-FPM jalan sebagai user akun, jadi kode di posapp/
# tidak butuh mode eksekusi (dijalankan via `php artisan`, bukan ./artisan).
find "$STAGING" -type d -exec chmod 755 {} +
find "$STAGING" -type f -exec chmod 644 {} +
[ -z "$(find "$STAGING" \( -type f -perm 660 -o -type d -perm 770 \) -print -quit)" ] \
    || fail "masih ada mode 0660/0770 di staging setelah normalisasi"
( cd "$STAGING" && zip -qr9 "../$OUT" . )
unzip -tq "$OUT" > /dev/null || fail "integritas ZIP rusak"
# Sync ZIP: hanya direktori public/ yang berubah tiap build dan dilayani dari
# docroot public_html/ (terpisah dari posapp/). Sengaja TIDAK ikut: index.php
# custom, .htaccess, favicon.ico, manifest.json PWA, robots.txt, sw.js, dan
# symlink storage — semuanya milik public_html/ dan tidak boleh tertimpa.
SYNC_DIRS=()
for d in build images inter screenshots; do
    [ -d "$STAGING/public/$d" ] && SYNC_DIRS+=("$d")
done
[ "${#SYNC_DIRS[@]}" -gt 0 ] || fail "tidak ada direktori public/ untuk sync ZIP"
( cd "$STAGING/public" && zip -qr9 "../../$SYNC_OUT" "${SYNC_DIRS[@]}" )
unzip -tq "$SYNC_OUT" > /dev/null || fail "integritas sync ZIP rusak"
# Verifikasi mode tersimpan di ZIP (bukan cuma di staging): tidak boleh ada
# entri file 0660 / folder 0770.
if zipinfo -v "$OUT" | grep -Eq '^-rw-rw----|drwxrwx---'; then
    fail "ZIP utama masih menyimpan mode 0660/0770"
fi
if zipinfo -v "$SYNC_OUT" | grep -Eq '^-rw-rw----|drwxrwx---'; then
    fail "sync ZIP masih menyimpan mode 0660/0770"
fi

echo ""
echo "SELESAI."
echo "  Package utama : $OUT ($(du -h "$OUT" | cut -f1))"
echo "  Entries       : $(zipinfo -1 "$OUT" | wc -l)"
echo "  Sync ZIP      : $SYNC_OUT ($(du -h "$SYNC_OUT" | cut -f1)) -> ekstrak ke public_html/"
echo "  Staging       : $STAGING/ ($(du -sh "$STAGING" | cut -f1))"
echo ""
echo "Langkah berikutnya (docs/production-deployment.md §2):"
echo "  1. Backup full account via panel DirectAdmin."
echo "  2. Upload $OUT ke hosting, ekstrak ke posapp/ (Merge and overwrite; .env JANGAN tertimpa)."
echo "  3. Upload $SYNC_OUT, ekstrak ke public_html/ (Merge and overwrite; index.php custom JANGAN tertimpa)."
echo "  4. Setelah upload: storage/, bootstrap/cache/ harus writable."
echo "  5. Jalankan artisan via cron sekali-jalan (migrate --force; storage:link; view:cache; scramble:cache — TANPA config:cache), lalu hapus cron-nya."
echo "  6. Smoke test: JS/CSS baru 200, manifest hash baru, login 200; hapus ZIP dari server."
