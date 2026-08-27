FROM php:8.4-fpm-alpine

# pdo_pgsql needs the postgres client headers; intl needs icu.
# -dev headers/static libs are build-only and purged after the extensions
# are compiled; only the runtime shared libraries ship in the final image.
#
# phpredis is the client config/database.php names (REDIS_CLIENT=phpredis).
# It is a PECL extension, not a bundled one, so it is compiled here; without
# it every Redis connection dies with `Class "Redis" not found`.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev \
 && apk add --no-cache postgresql-libs icu-libs git unzip \
 && docker-php-ext-install pdo_pgsql bcmath intl opcache \
 && pecl install redis \
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
