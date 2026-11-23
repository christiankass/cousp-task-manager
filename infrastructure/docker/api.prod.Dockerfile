# Image de production de l'API (PHP-FPM). Construite depuis la racine du dépôt :
#   docker compose -f docker-compose.prod.yml build api
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/api/composer.json backend/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs
COPY backend/api/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

FROM php:8.3-fpm-alpine
RUN apk add --no-cache postgresql-libs libzip icu-libs libpng freetype libjpeg-turbo fcgi \
 && apk add --no-cache --virtual .build postgresql-dev libzip-dev icu-dev libpng-dev freetype-dev libjpeg-turbo-dev $PHPIZE_DEPS \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install pdo_pgsql zip intl bcmath opcache gd pcntl \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del .build \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY infrastructure/production/php.ini "$PHP_INI_DIR/conf.d/zz-cousp.ini"
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app ./
COPY --chmod=755 infrastructure/production/scripts/api-entrypoint.sh /usr/local/bin/api-entrypoint

USER www-data
ENTRYPOINT ["api-entrypoint"]
CMD ["php-fpm"]
