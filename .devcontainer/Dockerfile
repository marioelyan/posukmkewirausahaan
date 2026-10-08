FROM mcr.microsoft.com/devcontainers/php:1-8.3-bookworm

# The base image ships a Yarn apt source (/etc/apt/sources.list.d/yarn.list)
# whose signing key is missing, so any `apt-get update` fails with exit 100 and
# breaks the container build. Drop that source before touching apt.
RUN rm -f /etc/apt/sources.list.d/yarn.list

# PHP extensions required by the app stack (MySQL/PDO, Excel, dompdf+GD, barcode/QR, intl).
# NOTE: no sudo here. This image defaults to root, and sudo strips PHP_INI_DIR, which
# makes docker-php-ext-enable write to /conf.d instead of the real conf.d directory.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
         libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libicu-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath gd intl zip exif pcntl \
    && rm -rf /var/lib/apt/lists/*
