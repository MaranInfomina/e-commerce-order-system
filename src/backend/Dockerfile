FROM php:8.4-fpm-alpine

# pdo_pgsql needs the postgres client headers; intl needs icu.
# -dev headers/static libs are build-only and purged after the extensions
# are compiled; only the runtime shared libraries ship in the final image.
#
# phpredis is the client config/database.php names (REDIS_CLIENT=phpredis).
# It is a PECL extension, not a bundled one, so it is compiled here; without
# it every Redis connection dies with `Class "Redis" not found`.
#
# Pinned. An unpinned `pecl install redis` floats to whatever is current at
# build time, so two builds of the same commit can ship different clients and
# a phpredis regression arrives without a diff. 6.3.0 is the version this
# milestone was developed and tested against; bump it deliberately.
#
# gd is a test and tooling dependency: UploadedFile::fake()->image() builds
# its fixtures with imagecreatetruecolor(). jpeg/webp are configured
# explicitly because the default build is png-only and ProductImageRequest
# accepts both, so a .jpg fake would still fatal.
#
# sockets is required by php-amqplib (pulled in transitively by
# vladimir-yuldashev/laravel-queue-rabbitmq) — without it `composer require`
# refuses to resolve at all ("ext-sockets * -> it is missing from your
# system"). It's a bundled PHP extension with no runtime shared library of
# its own, unlike pdo_pgsql/intl/gd above, but its build DOES need
# linux-headers on musl/Alpine: sockets.c includes <linux/sock_diag.h>,
# which glibc distros get for free but Alpine does not ship by default.
#
# pcntl is bundled (no external library, no -dev headers needed) and is used
# only by the checkout concurrency test (tests/Feature/CheckoutConcurrencyTest.php)
# to fork two real OS processes that race for the same row lock — it's a
# test-only need, but it's simplest to build it into the one shared image
# rather than maintain a separate test image. It is unsupported inside a
# traditional PHP-FPM SAPI worker, but Pest always runs under the plain CLI
# SAPI, where forking is safe.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev \
      libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev linux-headers \
 && apk add --no-cache postgresql-libs icu-libs git unzip \
      libpng libjpeg-turbo libwebp freetype \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install pdo_pgsql bcmath intl opcache gd sockets pcntl \
 && pecl install redis-6.3.0 \
 && docker-php-ext-enable redis \
 && rm -rf /tmp/pear \
 && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# The ./src/backend bind mount presents as root:root on Docker Desktop for
# Windows and chown against it is a silent no-op, but php-fpm's workers run
# as www-data. These directories are overlaid with named volumes in
# docker-compose.yml, which are seeded from the image (including ownership)
# when the container is first created.
RUN mkdir -p \
      storage/framework/cache/data \
      storage/framework/sessions \
      storage/framework/views \
      storage/framework/testing \
      bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

# php-fpm's own image already listens on 0.0.0.0:9000.
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
# Strip carriage returns: a CRLF script fails in Alpine with a misleading
# "no such file or directory". .gitattributes should prevent this; the sed
# is the second line of defence.
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
 && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm", "-F"]
