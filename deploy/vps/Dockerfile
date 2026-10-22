# KCK Sales Dashboard on the GSV VPS: the same PHP backend (php_backend/) as on Hostinger, unchanged,
# in Apache + PHP 8.3. Built by deploy/vps/docker-compose.yml (context = the repository root).
FROM php:8.3-apache

# PHP extensions the backend and PhpSpreadsheet need (the rest are built into the image)
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql zip gd

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# .htaccess needs mod_rewrite + AllowOverride; remoteip gives PHP the visitor's real IP behind Caddy
RUN a2enmod rewrite remoteip headers \
 && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf
COPY deploy/vps/apache-kck.conf /etc/apache2/conf-enabled/kck.conf
COPY deploy/vps/php-kck.ini /usr/local/etc/php/conf.d/kck.ini

WORKDIR /var/www/html
COPY php_backend/composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
COPY php_backend/ ./
# config.php (passwords, database) is NOT in the image: docker-compose mounts it from the VPS
RUN mkdir -p /var/kck-tmp && chown -R www-data:www-data /var/www/html /var/kck-tmp
