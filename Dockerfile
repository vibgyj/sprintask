FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev pkg-config \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo pdo_sqlite

WORKDIR /var/www/html

COPY . /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/sprintask-entrypoint

RUN a2enmod rewrite \
    && mkdir -p /var/www/html/var/data /var/www/html/var/log /var/www/html/var/sessions \
    && chown -R www-data:www-data /var/www/html/var \
    && chmod +x /usr/local/bin/sprintask-entrypoint

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

ENTRYPOINT ["/usr/local/bin/sprintask-entrypoint"]
CMD ["apache2-foreground"]
