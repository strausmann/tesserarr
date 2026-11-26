# syntax=docker/dockerfile:1
# check=skip=InvalidDefaultArgInFrom
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# =============================================================================
# Tesserarr container
#
# Stages:
#   php-base  FrankenPHP (Caddy + PHP 8.5, Debian 13) with the extensions the
#             app needs
#   vendor    Composer production install, package discovery, Wayfinder files
#   assets    Vite build of the web interface (Node only)
#   test      php-base + pcov + dev dependencies, for the test suite in CI;
#             never part of the runtime image
#   app       everything the published image holds
#   e2e       app plus the sign-in route of the browser tests and its marker
#             file; built for Playwright, never published
#   runtime   the published image: non-root, port 8080, one role per process.
#             The last stage, so a build without --target yields it.
#
# Build context is the repository root:
#   docker build $(python3 scripts/base_images.py cli) -f docker/Dockerfile -t tesserarr:dev .
#
# Base images are pinned by tag and digest in docker/base-images.env, and only
# there: these ARGs have no default, every build passes the pins as build
# arguments (scripts/base_images.py), and Renovate updates the file. A build
# without them stops with "base name should not be blank". That missing
# default is what the build check InvalidDefaultArgInFrom warns about, so the
# check directive above (line 2, a parser directive) skips it.
# https://frankenphp.dev/docs/docker/
# =============================================================================

ARG FRANKENPHP_IMAGE
ARG COMPOSER_IMAGE
ARG NODE_IMAGE

FROM ${COMPOSER_IMAGE} AS composer

# -----------------------------------------------------------------------------
FROM ${FRANKENPHP_IMAGE} AS php-base

# OPcache is part of the PHP binary since 8.5 and always loaded, so it is not
# installed here (php -m lists "Zend OPcache").
RUN install-php-extensions pdo_pgsql intl pcntl

WORKDIR /app

# -----------------------------------------------------------------------------
FROM php-base AS vendor

RUN install-php-extensions zip
COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Dependencies first: this layer is reused until composer.lock changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
# app/Testing and routes/testing.php are the sign-in of the browser tests;
# only the e2e stage puts them back (App\Runtime\E2eImage).
RUN rm -rf tests CLAUDE.md playwright.config.ts vitest.config.ts app/Testing routes/testing.php \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && php artisan package:discover --ansi \
    && php artisan wayfinder:generate --with-form

# -----------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS assets

WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY vite.config.ts tsconfig.json svelte.config.js components.json ./
COPY resources/ resources/
COPY public/ public/
# Wayfinder needs PHP; its files were generated in the vendor stage, so the
# Vite plugin runs a no-op here (vite.config.ts reads WAYFINDER_COMMAND).
COPY --from=vendor /app/resources/js/actions resources/js/actions
COPY --from=vendor /app/resources/js/routes resources/js/routes
COPY --from=vendor /app/resources/js/wayfinder resources/js/wayfinder
ENV WAYFINDER_COMMAND=true
# The version the bundle is built for (vite.config.ts): the same build
# argument the server reads in the app stage, so both report one version.
ARG TESSERARR_VERSION=0.0.0-dev
RUN npm run build

# -----------------------------------------------------------------------------
FROM php-base AS test

RUN install-php-extensions pcov zip
COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
# The suite expects a .env as in a checkout; APP_KEY comes from the environment.
RUN cp .env.example .env
COPY --from=assets /app/public/build public/build
COPY --from=vendor /app/resources/js/actions resources/js/actions
COPY --from=vendor /app/resources/js/routes resources/js/routes
COPY --from=vendor /app/resources/js/wayfinder resources/js/wayfinder
RUN composer dump-autoload --no-interaction && php artisan package:discover --ansi

CMD ["./vendor/bin/pest"]

# -----------------------------------------------------------------------------
FROM php-base AS app

ARG REVISION=unknown
ARG BUILD_DATE=1970-01-01T00:00:00Z

# https://specs.opencontainers.org/image-spec/annotations/
LABEL org.opencontainers.image.title="Tesserarr" \
      org.opencontainers.image.description="Share parts of your media library with friends over an existing Syncthing link" \
      org.opencontainers.image.source="https://github.com/strausmann/tesserarr" \
      org.opencontainers.image.authors="Björn Strausmann <projects@strausmann.ch>" \
      org.opencontainers.image.vendor="strausmann" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.revision="${REVISION}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.base.name="docker.io/dunglas/frankenphp:1.14.0-php8.5-trixie"

# pg_dump for `artisan tesserarr:migrate` comes from postgresql-client-18, the
# major version of the database (pg_dump must not be older than the server).
# Debian 13 only ships 17, so the package comes from the PostgreSQL apt
# repository (apt.postgresql.org, https://www.postgresql.org/download/linux/debian/).
# Its signing key is pinned by checksum
# (key B97B 0AFC AA1A 47F0 44F2 44A0 7FCC 7D46 ACCC 4CF8). Source and key are
# removed again after the install: the final image lists no third-party
# repository, and a later apt-get in a derived stage does not use PGDG.
#
# apt-get upgrade takes every package of the base image to the current Debian
# security release: the weekly rebuild (image-rebuild.yml) relies on it to ship
# fixed packages before the base image itself is rebuilt upstream. A published
# build (docker-publish.yml) passes a new BUILD_DATE above, so it never takes
# this layer from the cache; the rebuild builds without a cache at all.
#
# The base image carries the compiler toolchain for phpize ($PHPIZE_DEPS) and
# the C and kernel headers; the runtime needs none of it, and it holds most of
# the image's CVE findings. libc-dev in $PHPIZE_DEPS is a virtual package, so
# libc6-dev and linux-libc-dev are named explicitly. install-php-extensions
# marks every package it saw as manually installed, the compiler (gcc-N, cpp,
# binutils) and its libraries included, so autoremove would keep them: they
# are marked automatic first, then apt removes whatever nothing else needs.
# gcc-N-base is left alone, libgcc-s1 depends on it. curl (health check) and
# libcap2-bin (setcap below) stay.
ARG PGDG_ASC_SHA256=0144068502a1eddd2a0280ede10ef607d1ec592ce819940991203941564e8e76
RUN curl --fail --silent --show-error --location --output /usr/share/keyrings/pgdg.asc \
        https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && echo "${PGDG_ASC_SHA256}  /usr/share/keyrings/pgdg.asc" | sha256sum --check --strict \
    && echo "deb [signed-by=/usr/share/keyrings/pgdg.asc] https://apt.postgresql.org/pub/repos/apt trixie-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get upgrade -y --no-install-recommends \
    && apt-get install -y --no-install-recommends tini postgresql-client-18 \
    && apt-mark auto $(dpkg-query --show --showformat '${db:Status-Abbrev} ${Package}\n' \
        | awk '/^ii/ {print $2}' \
        | grep -E '^(cpp|gcc|g\+\+|binutils|libbinutils|libgcc-[0-9]+-dev|libstdc\+\+-[0-9]+-dev|libasan|libcc1|libhwasan|libisl|libitm|liblsan|libmpc|libmpfr|libquadmath|libtsan|libubsan|libgomp|libatomic|libctf|libsframe|libjansson|libc-dev-bin|libcrypt-dev|m4$|patch$|pkgconf|rpcsvc-proto)' \
        | grep -vE '^gcc-[0-9]+-base') >/dev/null \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS libc6-dev linux-libc-dev \
    && rm -rf /var/lib/apt/lists/* /etc/apt/sources.list.d/pgdg.list /usr/share/keyrings/pgdg.asc

# Production php.ini plus our overrides. zend.exception_ignore_args keeps
# function arguments (addresses, tokens) out of every stack trace.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-tesserarr.ini"

# Non-root: UID 10001. FrankenPHP needs no capability to bind :8080, so the
# image's cap_net_bind_service is removed.
RUN groupadd --system --gid 10001 tesserarr \
    && useradd --system --uid 10001 --gid tesserarr --home-dir /app --no-create-home tesserarr \
    && setcap -r /usr/local/bin/frankenphp \
    && chown -R tesserarr:tesserarr /data/caddy /config/caddy \
    && install -d -o tesserarr -g tesserarr -m 0700 /var/lib/tesserarr/backups

# Code belongs to root and is read-only for the runtime user; only storage/
# and bootstrap/cache/ are writable.
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
RUN chown -R tesserarr:tesserarr /app/storage /app/bootstrap/cache
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint

# Declared this late so that a build for another version (the second image of
# the browser tests, scripts/e2e/run.sh) reuses every layer above.
ARG TESSERARR_VERSION=0.0.0-dev
LABEL org.opencontainers.image.version="${TESSERARR_VERSION}"

ENV TESSERARR_VERSION="${TESSERARR_VERSION}" \
    SERVER_NAME=":8080" \
    LOG_CHANNEL=stderr

USER tesserarr
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl --fail --silent --show-error --output /dev/null http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# -----------------------------------------------------------------------------
# The browser tests run against the production image with one difference: the
# sign-in route /_testing/login/{role}. Its two files and the marker exist in
# this stage only (App\Runtime\E2eImage); the environment stays as in
# production. The class map is rebuilt so the controller can be loaded.
FROM app AS e2e

USER root
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY app/Testing/ /app/app/Testing/
COPY routes/testing.php /app/routes/testing.php
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts --no-interaction \
    && rm /usr/local/bin/composer \
    && touch /app/.e2e-image
USER tesserarr

# -----------------------------------------------------------------------------
FROM app AS runtime
