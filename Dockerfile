FROM php:8.2-apache

RUN docker-php-ext-install mysqli

RUN { \
    echo "upload_max_filesize=10G"; \
    echo "post_max_size=10G"; \
    echo "max_file_uploads=10000"; \
    echo "max_input_vars=20000"; \
    echo "max_execution_time=0"; \
    echo "max_input_time=0"; \
    echo "memory_limit=1024M"; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# remove Apache body limit (default unlimited, but set explicitly)
RUN echo "LimitRequestBody 0" > /etc/apache2/conf-available/upload-limit.conf \
    && a2enconf upload-limit

COPY app/ /var/www/html/
RUN mkdir -p /var/www/html/uploads

RUN chown -R www-data:www-data /var/www/html/uploads
