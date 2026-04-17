FROM pader/wind-php:1.0.0

#COPY php.ini /usr/local/etc/php/

COPY --chown=www-data:www-data . /home/www-data/
RUN composer install --verbose --no-dev --optimize-autoloader
