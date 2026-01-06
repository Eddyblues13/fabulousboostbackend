# Use official PHP 8.2 CLI image (Debian Bookworm-based)
FROM php:8.2-cli

# Set working directory
WORKDIR /var/www/html

# Install system dependencies and PHP extensions needed by Laravel 12
RUN apt-get update && apt-get install -y \
    git unzip zip curl libpng-dev libonig-dev libxml2-dev libzip-dev libjpeg-dev libfreetype6-dev libpq-dev libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql mbstring zip exif pcntl bcmath gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer globally
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application code
COPY . .

# Install PHP dependencies optimized for production
RUN composer install --no-dev --optimize-autoloader

# Set permissions (important for Laravel)
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Expose port 9000 (Railway default HTTP port)
EXPOSE 9000

# Start Laravel’s built-in server binding to 0.0.0.0:9000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=9000"]
