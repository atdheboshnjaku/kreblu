# Kreblu Development Dockerfile
# PHP 8.5 + Nginx + required extensions
FROM php:8.5-fpm

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
    libicu-dev \
    default-mysql-client \
    nginx \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# Use install-php-extensions (handles PHP 8.5 built-in detection correctly)
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
    pdo_mysql \
    mysqli \
    gd \
    zip \
    intl

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