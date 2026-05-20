FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install mysqli mbstring opcache \
    && a2enmod rewrite headers expires deflate remoteip \
    && a2disconf other-vhosts-access-log \
    && apt-get purge -y --auto-remove libonig-dev \
    && rm -rf /var/lib/apt/lists/*

ENV PORT=8000
ENV APACHE_DOCUMENT_ROOT=/var/www/html

WORKDIR /var/www/html

COPY docker/apache/ports.conf /etc/apache2/ports.conf
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-posmain-production.ini
COPY . /var/www/html

RUN mkdir -p /var/www/html/logs /var/www/html/uploads /var/www/html/var/sessions \
    && chown -R www-data:www-data /var/www/html/logs /var/www/html/uploads /var/www/html/var \
    && chmod -R u+rwX,g+rwX /var/www/html/logs /var/www/html/uploads /var/www/html/var

EXPOSE 8000

CMD ["apache2-foreground"]
