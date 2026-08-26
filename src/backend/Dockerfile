FROM php:8.4-fpm-alpine

# pdo_pgsql needs the postgres client headers; intl needs icu.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
 && apk add --no-cache postgresql-dev icu-dev git unzip \
 && docker-php-ext-install pdo_pgsql bcmath intl opcache \
 && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# php-fpm's own image already listens on 0.0.0.0:9000.
EXPOSE 9000

CMD ["php-fpm", "-F"]
