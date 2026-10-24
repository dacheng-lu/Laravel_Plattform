FROM serversideup/php:8.4-fpm-nginx AS app

USER root
RUN install-php-extensions intl pdo_mysql
USER www-data

COPY --chown=www-data:www-data . /var/www/html
RUN composer install --no-dev --optimize-autoloader --no-interaction

FROM node:22-alpine AS assets
WORKDIR /app
COPY --from=app /var/www/html /app
RUN npm ci && npm run build

FROM app
COPY --chown=www-data:www-data --from=assets /app/public/build /var/www/html/public/build

# Run migrations etc. on container start (serversideup Laravel automations).
ENV AUTORUN_ENABLED=true
