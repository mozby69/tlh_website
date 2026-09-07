FROM php:8.4-apache

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    unzip \
    git \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        mysqli \
        pdo \
        pdo_mysql \
        zip \
        gd \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

RUN printf "display_errors=Off\n\
log_errors=On\n\
max_execution_time=300\n\
memory_limit=256M\n\
upload_max_filesize=50M\n\
post_max_size=50M\n" \
    > /usr/local/etc/php/conf.d/production.ini

EXPOSE 80

CMD ["apache2-foreground"]