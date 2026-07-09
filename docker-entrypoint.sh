#!/bin/bash

set -e

echo "Preparing Laravel storage..."


STORAGE_LINK="/var/www/html/public/storage"


# Remove existing wrong storage folder/link
if [ -e "$STORAGE_LINK" ] || [ -L "$STORAGE_LINK" ]; then

    echo "Removing existing storage link..."

    rm -rf "$STORAGE_LINK"

fi


# Create Laravel storage link
php artisan storage:link || true


php artisan config:cache
php artisan route:cache
php artisan view:cache


chown -R www-data:www-data storage bootstrap/cache

chmod -R 775 storage bootstrap/cache


exec apache2-foreground