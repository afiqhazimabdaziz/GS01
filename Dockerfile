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
# Stage 2: Laravel + Apache
# ==========================
FROM php:8.3-apache

WORKDIR /var/www/html


# Install PHP extensions
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
COPY . .


# Copy Vite build assets
COPY --from=frontend /app/public/build ./public/build


# Install Laravel dependencies
RUN composer install \
    --no-interaction \
    --optimize-autoloader \
    --no-dev \
    --ignore-platform-reqs


# Apache document root -> Laravel public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf

RUN sed -ri \
    -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Fix Apache MPM conflict
RUN a2dismod mpm_event mpm_worker mpm_prefork || true \
    && a2enmod mpm_prefork rewrite


# Create startup script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN chmod +x /usr/local/bin/docker-entrypoint.sh


# Fix permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 storage bootstrap/cache


EXPOSE 80


ENTRYPOINT ["docker-entrypoint.sh"]