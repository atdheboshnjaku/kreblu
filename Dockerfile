# Kreblu Development Dockerfile
# Target: PHP 8.5+ (using 8.3-fpm as base until 8.5 images are available)
# When PHP 8.5 is released, change the FROM line to: php:8.5-fpm
FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    libicu-dev \
    libonig-dev \
    libcurl4-openssl-dev \
    default-mysql-client \
    nginx \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        mysqli \
        gd \
        zip \
        xml \
        intl \
        mbstring \
        curl \
        fileinfo \
        opcache

# Install Composer (dev only - not shipped with the product)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# PHP configuration for development
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/php/kreblu.ini /usr/local/etc/php/conf.d/kreblu.ini

# Nginx configuration
COPY docker/nginx/default.conf /etc/nginx/sites-available/default

# Supervisor to run both nginx and php-fpm
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Set working directory
WORKDIR /var/www/kreblu

# Expose port 80
EXPOSE 80

# Start supervisor (manages nginx + php-fpm)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
