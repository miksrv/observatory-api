FROM php:8.2-apache

# Enable Apache mod_rewrite (required for CI4)
RUN a2enmod rewrite

# Install required PHP extensions.
# unzip: Composer needs it (or the php zip extension) to install packages from dist archives.
RUN apt-get update && apt-get install -y \
    libicu-dev \
    unzip \
    && docker-php-ext-install mysqli pdo_mysql intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer, so dependencies can be installed/updated inside this same PHP 8.2
# runtime instead of whatever PHP version happens to be on the host.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set document root to CI4's public/ directory
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-enabled/000-default.conf

# Allow .htaccess overrides
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
