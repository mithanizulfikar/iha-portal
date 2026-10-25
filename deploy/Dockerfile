# Starting point for container hosting (UNTESTED: build it and try /health before relying on it).
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev unzip git \
    && docker-php-ext-install pdo_pgsql pgsql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/iha
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && mkdir -p storage/logs storage/uploads storage/generated storage/backups \
    && chown -R www-data:www-data storage

# The web server serves ONLY the public/ folder; src/, templates/, database/, .env stay outside the web root.
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
EXPOSE 80
