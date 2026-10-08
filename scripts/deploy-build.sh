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
#   deploy-package-YYYYMMDD.zip  package untuk diunggah via File Manager/FTP
#
# Syarat: PHP 8.3 + Composer 2, Node 18+ / npm, zip, rsync.

set -euo pipefail
cd "$(dirname "$0")/.."

STAGING="deploy"
OUT="deploy-package-$(date +%Y%m%d).zip"

fail() { echo "GAGAL: $1" >&2; exit 1; }

if [ -e "$OUT" ]; then
    fail "$OUT sudah ada. Hapus manual dulu jika ingin rebuild (package lama tidak ditimpa otomatis)."
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
rsync -a \
    --exclude='public/hot' \
    --exclude='public/storage' \
    --exclude='storage/framework/views/*.php' \
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
[ -z "$(find "$STAGING" -type l)" ] || fail "ada symlink di staging (public/storage laptop tidak boleh ikut)"
if grep -rIl 'APP_KEY=base64:' "$STAGING" > /dev/null 2>&1; then
    fail "ditemukan APP_KEY terisi di staging"
fi

echo "==> [7/7] Membuat ${OUT}"
( cd "$STAGING" && zip -qr9 "../$OUT" . )
unzip -tq "$OUT" > /dev/null || fail "integritas ZIP rusak"

echo ""
echo "SELESAI."
echo "  Package : $OUT ($(du -h "$OUT" | cut -f1))"
echo "  Entries : $(zipinfo -1 "$OUT" | wc -l)"
echo "  Staging : $STAGING/ ($(du -sh "$STAGING" | cut -f1))"
echo ""
echo "Langkah berikutnya (Section 3+):"
echo "  1. Buat .env produksi TERPISAH (bukan dari repo, jangan masukkan ke ZIP)."
echo "  2. Upload isi $OUT ke hosting, ekstrak via File Manager."
echo "  3. Setelah upload: storage/, bootstrap/cache/ harus writable."
echo "  4. Jalankan artisan via Cron (migrate --force, storage:link) — lihat rencana deploy."
