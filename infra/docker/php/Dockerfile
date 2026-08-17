FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libzip-dev libicu-dev libonig-dev \
    && docker-php-ext-install pdo pdo_pgsql zip intl opcache

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/yzendra-id
