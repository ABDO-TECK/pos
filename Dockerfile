FROM php:8.2-apache

# Install dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo_mysql zip

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy project files (in actual production, you'd only copy dist and backend)
COPY . /var/www/html/

# Set Permissions
RUN chown -R www-data:www-data /var/www/html
