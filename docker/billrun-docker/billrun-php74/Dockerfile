from php:7.4-fpm
# https://github.com/netroby/docker-php-fpm/blob/master/Dockerfile

RUN apt-get update && apt-get install -y \
        wkhtmltopdf \
    && pecl install yaf-3.1.4 \
    && pecl install mongodb-1.11.1 \
    && pecl install xdebug-3.1.5 \
    && docker-php-ext-enable yaf mongodb xdebug \
    && docker-php-ext-install pcntl bcmath

COPY php-fpm.conf /usr/local/etc/
COPY php.ini /usr/local/etc/php/
COPY xdebug.ini /usr/local/etc/php/conf.d/
CMD ["php-fpm"]
