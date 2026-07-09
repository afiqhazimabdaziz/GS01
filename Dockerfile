# ==========================
# Stage 1: Build frontend
# ==========================

FROM node:20 AS frontend

WORKDIR /app

COPY package*.json ./

RUN npm install

COPY . .

RUN npm run build



# ==========================
# Stage 2: PHP-FPM Laravel
# ==========================

FROM php:8.3-fpm


WORKDIR /var/www/html



# Install dependencies

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
    && rm -rf /var/lib/apt/lists/*



# Install Composer

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer



# Copy Laravel application

COPY . /var/www/html



# Copy Vite production assets

COPY --from=frontend \
    /app/public/build \
    /var/www/html/public/build



# Install Laravel dependencies

RUN composer install \
    --no-interaction \
    --optimize-autoloader \
    --no-dev \
    --ignore-platform-reqs



# Laravel cache

RUN php artisan key:generate \
    && php artisan storage:link \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache



# Permissions

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache



# PHP-FPM listens here

EXPOSE 9000


CMD ["php-fpm"]