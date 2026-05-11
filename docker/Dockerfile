# Stage 1: Build
FROM php:8.4-fpm-alpine AS builder

WORKDIR /var/www/html

RUN apk add --no-cache git curl zip unzip \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . /var/www/html
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Stage 2: Runtime
FROM php:8.4-fpm-alpine
LABEL org.opencontainers.image.source=https://github.com/lintune/lintune-admin

WORKDIR /var/www/html/lintune-admin

RUN apk add --no-cache nginx mariadb-client bash \
    && docker-php-ext-install pdo_mysql opcache \
    && echo "memory_limit = 256M" > /usr/local/etc/php/conf.d/lintune.ini

COPY --from=builder /var/www/html /var/www/html/lintune-admin
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
