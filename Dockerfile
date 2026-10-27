# syntax=docker/dockerfile:1.7
# Postivo — image aplikasi (php-fpm) dan image web (Caddy + aset statis).
#   docker build --target app -t postivo-app .
#   docker build --target web -t postivo-web .

# ---- 1. Aset front-end ------------------------------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- 2. Dependensi PHP (tanpa dev) -------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader

# ---- 3. Aplikasi (php-fpm) ---------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS app
ADD https://github.com/mlocati/docker-php-extension-installer/releases/download/2.7.6/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_mysql bcmath intl opcache zip \
    && apk add --no-cache tini
WORKDIR /var/www/html
COPY --from=vendor /build/vendor ./vendor
COPY . .
COPY --from=assets /build/public/build ./public/build
COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-postivo.ini
COPY deploy/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/app/spool storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/entrypoint"]
CMD ["php-fpm"]

# ---- 4. Web (Caddy: TLS otomatis + berkas statis + fastcgi ke app) -------------------------------
FROM caddy:2-alpine AS web
COPY deploy/Caddyfile /etc/caddy/Caddyfile
COPY --from=app /var/www/html/public /var/www/html/public

# ---- 5. Web untuk Coolify (tanpa TLS: TLS & domain ditangani proxy Coolify) ------------------------
FROM caddy:2-alpine AS web-coolify
COPY deploy/Caddyfile.coolify /etc/caddy/Caddyfile
COPY --from=app /var/www/html/public /var/www/html/public
