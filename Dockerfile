FROM php:8.2-apache

# Enable Apache mod_rewrite (required for CI4)
RUN a2enmod rewrite

# Install required PHP extensions
RUN apt-get update && apt-get install -y \
    libicu-dev \
    && docker-php-ext-install mysqli pdo_mysql intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Set document root to CI4's public/ directory
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-enabled/000-default.conf

# Allow .htaccess overrides
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
