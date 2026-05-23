FROM php:8.2-apache

COPY . /var/www/html/

RUN mkdir -p /var/www/html/leads && \
    chmod -R 777 /var/www/html/leads

EXPOSE 80
