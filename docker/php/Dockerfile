FROM php:8.1-apache

# Install system packages and PHP extensions
RUN apt-get update \
  && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev libonig-dev libpng-dev libjpeg-dev libfreetype6-dev zlib1g-dev libxml2-dev curl \
  && docker-php-ext-install pdo_mysql mbstring intl opcache zip exif

# Enable apache mods
RUN a2enmod rewrite headers

# Configure document root
ENV APACHE_DOCUMENT_ROOT /var/www/html

# set recommended PHP.ini settings for web app (small)
# build context for this service is ./docker/php, so copy php.ini from the context root
COPY php.ini /usr/local/etc/php/conf.d/app.ini

# Ensure uploads dir exists
RUN mkdir -p /var/www/html/uploads && chown -R www-data:www-data /var/www/html/uploads

# Expose port 80
EXPOSE 80

# Use the default apache entrypoint provided by the base image
