FROM mcr.microsoft.com/devcontainers/php:1-8.3-bookworm

# PHP extensions required by the app stack (MySQL/PDO, Excel, dompdf+GD, barcode/QR, intl).
# The devcontainers PHP image bundles the idempotent `install-php-extensions` helper;
# fall back to the raw docker-php-ext helper if it is ever missing.
RUN if command -v install-php-extensions >/dev/null 2>&1; then \
      sudo install-php-extensions pdo_mysql bcmath gd intl zip exif pcntl; \
    else \
      sudo apt-get update \
      && sudo apt-get install -y --no-install-recommends \
           libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libicu-dev libonig-dev \
      && sudo docker-php-ext-configure gd --with-freetype --with-jpeg \
      && sudo docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath gd intl zip exif pcntl \
      && sudo rm -rf /var/lib/apt/lists/*; \
    fi
