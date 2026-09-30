# CortenDesk — self-hosted RustDesk console, ID server and relay in one image.
#
#   docker build -t cortendesk .
#   docker run -p 8080:8080 -p 21115-21119:21115-21119 -p 21116:21116/udp \
#     -v cortendesk-data:/data cortendesk
#
# Zero-config run uses SQLite in the /data volume, generates the server key on
# first boot and creates admin/changeme. See docker-compose.yml for a MySQL
# setup, and README.md for the reverse-proxy (TLS + wss) notes.

# ---- stage 0: the ID and relay servers ---------------------------------------
# Prebuilt static binaries, lifted out of a published image — nothing is
# compiled here. Source: github.com/marcpope/cortendesk-server (AGPL-3.0, a
# fork of rustdesk-server). Pinned to an exact version on purpose: the server
# has its own release cadence, and a console release should never quietly
# change which server it ships.
ARG SERVER_VERSION=1.1.0
FROM ghcr.io/marcpope/cortendesk-server:${SERVER_VERSION} AS server

# ---- stage 1: composer dependencies -----------------------------------------
FROM composer:2 AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
# The storage/cache skeleton must exist for artisan package:discover (git
# doesn't track empty directories, so don't trust the checkout).
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing \
      storage/framework/views storage/logs storage/app/private storage/app/public bootstrap/cache \
    && composer dump-autoload --optimize --no-dev

# ---- stage 2: runtime (php-fpm + nginx under supervisord) --------------------
FROM php:8.4-fpm-alpine

RUN apk add --no-cache nginx supervisor icu-libs gettext \
    && apk add --no-cache --virtual .build icu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath opcache intl \
    && apk del .build

WORKDIR /app
COPY --from=deps /app /app
COPY --from=server /usr/bin/hbbs /usr/bin/hbbr /usr/bin/cortendesk-utils /usr/bin/
COPY docker/php.ini /usr/local/etc/php/conf.d/cortendesk.ini
COPY docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/rustdesk-server.conf /etc/cortendesk/rustdesk-server.conf
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh /usr/bin/hbbs /usr/bin/hbbr \
    && mkdir -p /data /run/nginx /etc/supervisor.d \
    && chown -R www-data:www-data /data /app/storage /app/bootstrap/cache

# Container-friendly defaults; override any of these at run time.
ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost:8080 \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/cortendesk.sqlite \
    SESSION_DRIVER=file \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    CORTENDESK_NATIVE_WEBCLIENT=true \
    CORTENDESK_EMBEDDED_SERVER=true

# Carried through so the console can report which server it is running, and so
# `docker inspect` answers the question without a shell.
ARG SERVER_VERSION=1.1.0
ENV CORTENDESK_SERVER_VERSION=${SERVER_VERSION}

# 8080 console/API. 21115 NAT test, 21116 tcp+udp signalling, 21118 ws (hbbs);
# 21117 relay, 21119 ws (hbbr). The ws pair only needs to be reachable from
# this container — nginx bridges /ws/id and /ws/relay to them over loopback.
EXPOSE 8080 21115 21116 21116/udp 21117 21118 21119
VOLUME /data

# Liveness, not readiness: /health/ready probes the ID server and relay, and
# with the embedded server that is this same container — but with external
# servers a relay outage would mark the console unhealthy and restart-loop it.
# The container check answers "is the app up"; point monitoring at
# /health/ready for the full dependency picture.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/health/live || exit 1

ENTRYPOINT ["/entrypoint.sh"]
