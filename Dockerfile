FROM php:8.4-fpm

WORKDIR /var/www

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl zip unzip libpq-dev ffmpeg

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql pcntl opcache

# Allow large video uploads to reach Laravel
RUN { \
    echo "upload_max_filesize=100M"; \
    echo "post_max_size=100M"; \
    echo "memory_limit=256M"; \
    echo "max_execution_time=300"; \
    echo "default_socket_timeout=300"; \
} > /usr/local/etc/php/conf.d/uploads.ini

# Keep PHP bytecode in memory for API and worker processes.
RUN {     echo "opcache.enable=1";     echo "opcache.enable_cli=1";     echo "opcache.memory_consumption=128";     echo "opcache.interned_strings_buffer=16";     echo "opcache.max_accelerated_files=20000";     echo "opcache.validate_timestamps=1";     echo "opcache.revalidate_freq=2"; } > /usr/local/etc/php/conf.d/opcache.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy project files
COPY . .

# Install dependencies
RUN BROADCAST_CONNECTION=log composer install

EXPOSE 9000

# Passport refuses to read private keys that are group/world-writable.
# The repo is bind-mounted in Docker, so we fix the mode on container start.
CMD ["sh", "-lc", "if [ -f /var/www/storage/oauth-private.key ]; then chmod 600 /var/www/storage/oauth-private.key; fi; if [ -f /var/www/storage/oauth-public.key ]; then chmod 600 /var/www/storage/oauth-public.key; fi; exec php-fpm"]


