FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM php:8.4-cli@sha256:1d2cf2b7d715b5b3aa959f0150ccbcc7b71e0181b637e443abf40a7fb56bf79a
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev libonig-dev libfreetype-dev libjpeg62-turbo-dev libpng-dev libwebp-dev && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && docker-php-ext-install pdo_mysql intl zip mbstring bcmath gd && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
