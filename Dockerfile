FROM php:8.2-apache

# Install PHP extensions required for database access
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache mod_rewrite for REST routing
RUN a2enmod rewrite headers

# Point Apache DocumentRoot to the public/ folder
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Copy project files
COPY . /var/www/html

# Ensure write permissions for storage logs and media uploads
RUN mkdir -p /var/www/html/storage/logs \
    /var/www/html/storage/private \
    /var/www/html/public/uploads/properties \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/public/uploads \
    && chmod -R 775 /var/www/html/storage /var/www/html/public/uploads

EXPOSE 80

CMD ["apache2-foreground"]
