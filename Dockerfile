FROM php:8.2-apache
RUN docker-php-ext-install curl
COPY . /var/www/html/
RUN chmod 777 /var/www/html/estado.json 2>/dev/null || true
EXPOSE 80
