# ONA (legacy UI) + REST API on PHP 8.3 / Apache.
# Build context is the api/ folder:  docker build -f docker/Dockerfile .
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libgmp-dev \
 && docker-php-ext-install gmp mysqli \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers

COPY docker/apache-ona.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php-ona.ini /usr/local/etc/php/conf.d/ona.ini
COPY docker/entrypoint.sh /usr/local/bin/ona-entrypoint
COPY . /opt/ona

RUN chmod 755 /usr/local/bin/ona-entrypoint \
 && echo /opt/ona > /etc/onabase \
 && touch /var/log/ona.log && chown www-data /var/log/ona.log \
 && chown -R www-data /opt/ona/www/local

WORKDIR /opt/ona
EXPOSE 80
ENTRYPOINT ["ona-entrypoint"]
CMD ["apache2-foreground"]
