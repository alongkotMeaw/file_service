FROM php:8.2-apache

RUN docker-php-ext-install mysqli

COPY app/ /var/www/html/
RUN mkdir -p /var/www/html/uploads

RUN chown -R www-data:www-data /var/www/html/uploads
