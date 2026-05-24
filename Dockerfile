FROM php:8.3-cli-alpine

RUN apk add --no-cache git unzip gmp-dev \
 && docker-php-ext-install gmp \
 && rm -rf /var/cache/apk/*

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
