FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    unzip \
    curl \
    netcat-openbsd \
    icu-dev \
    libzip-dev \
    linux-headers \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mysqli \
    intl \
    zip \
    opcache

# Install and configure APCu
RUN pecl install apcu && \
    docker-php-ext-enable apcu

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configure PHP for production/development
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# PHP configuration
RUN echo "memory_limit=256M" >> $PHP_INI_DIR/conf.d/custom.ini && \
    echo "upload_max_filesize=20M" >> $PHP_INI_DIR/conf.d/custom.ini && \
    echo "post_max_size=20M" >> $PHP_INI_DIR/conf.d/custom.ini && \
    echo "max_execution_time=300" >> $PHP_INI_DIR/conf.d/custom.ini

# OPcache configuration for production
RUN echo "opcache.enable=1" >> $PHP_INI_DIR/conf.d/opcache.ini && \
    echo "opcache.memory_consumption=128" >> $PHP_INI_DIR/conf.d/opcache.ini && \
    echo "opcache.max_accelerated_files=10000" >> $PHP_INI_DIR/conf.d/opcache.ini && \
    echo "opcache.validate_timestamps=0" >> $PHP_INI_DIR/conf.d/opcache.ini

# Set working directory
WORKDIR /var/www/html

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

# Create necessary directories with proper permissions
RUN mkdir -p /var/www/html/var && \
    chown -R www-data:www-data /var/www/html/var

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint"]
CMD ["php-fpm"]