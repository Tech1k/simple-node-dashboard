FROM php:8-apache

WORKDIR /var/www/html
COPY LICENSE index.php README.md ./

# The dashboard writes {network}_cache.json into the web root, so the runtime
# user (www-data) needs write access to it.
RUN chown -R www-data:www-data /var/www/html

# Run as www-data (non-root)
USER www-data
