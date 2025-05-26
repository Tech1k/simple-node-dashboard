FROM php:8-apache

# www-data user
USER 33
WORKDIR /var/www/html
COPY LICENSE index.php README.md ./

