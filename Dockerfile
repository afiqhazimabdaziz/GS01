# ==========================
# Stage 1: Build Laravel
# ==========================

FROM php:8.3-fpm AS builder


RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    unzip \
    git \
    libpq-dev \
    libonig-dev \
    libssl-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libicu-dev \
    libzip-dev \
    nodejs \
    npm \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        pdo_pgsql \
        pgsql \
        intl \
        zip \
        bcmath \
        soap \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*


WORKDIR /var/www


COPY . .


# Composer

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer


RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist


# Build frontend

RUN npm install \
    && npm run build



# ==========================
# Stage 2: Production PHP-FPM
# ==========================

FROM php:8.3-fpm AS production


RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    libfcgi-bin \
    procps \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*



# PHP production config

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"



# Copy PHP extensions

COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/

COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/


# Application

COPY --from=builder /var/www /var/www


WORKDIR /var/www



# Storage

RUN mkdir -p storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    bootstrap/cache


RUN chown -R www-data:www-data /var/www


USER www-data



COPY docker/php-fpm/entrypoint.sh /usr/local/bin/entrypoint.sh


RUN chmod +x /usr/local/bin/entrypoint.sh


ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]


EXPOSE 9000


CMD ["php-fpm"]