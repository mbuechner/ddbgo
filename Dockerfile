FROM composer:2 AS COMPOSER_CHAIN
COPY / /tmp/ddbgo
WORKDIR /tmp/ddbgo
RUN composer install --no-dev

# Add git tag version to status page in DDBgo
RUN sed -i -e "s:{{version}}:$(git describe --tags):g" web/modules/custom/ddbgo_workarounds/ddbgo_workarounds.install; \
     sed -i -e "s:{{commitid}}:$(git rev-parse HEAD):g" web/modules/custom/ddbgo_workarounds/ddbgo_workarounds.install; \
     rm -rf .git/ config/;

FROM php:8.0-fpm-alpine
MAINTAINER Michael Büchner <m.buechner@dnb.de>

# Install packages
RUN apk --no-cache add \
    curl \
    nginx \
    nginx-mod-http-upload-progress \
    nginx-mod-http-brotli \
    supervisor;

RUN set -eux; \
     \
     apk add --no-cache --virtual .build-deps \
          coreutils \
          freetype-dev \
          libjpeg-turbo-dev \
          libpng-dev \
          libzip-dev \
          pcre-dev \
          autoconf \
          g++ \
          make \
          git \
          # postgresql-dev is needed for https://bugs.alpinelinux.org/issues/3642
          postgresql-dev; \
     \
     docker-php-ext-configure gd \
          --with-freetype \
          --with-jpeg=/usr/include; \
     \
     docker-php-ext-install -j "$(nproc)" \
          gd \
          opcache \
          pdo_mysql \
          pdo_pgsql \
          zip; \
     pecl channel-update pecl.php.net; \
     pecl install oauth apcu uploadprogress; \
     docker-php-ext-enable apcu oauth uploadprogress; \
     \
     runDeps="$( \
          scanelf --needed --nobanner --format '%n#p' --recursive /usr/local \
               | tr ',' '\n' \
               | sort -u \
               | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }' \
     )"; \
     apk add --no-network --virtual .drupal-phpexts-rundeps $runDeps; \
     apk del --no-network .build-deps \
          coreutils \
          freetype-dev \
          libjpeg-turbo-dev \
          libpng-dev \
          libzip-dev \
          pcre-dev \
          autoconf \
          g++ \
          make \
          git \
          postgresql-dev;

# add PHP config
COPY ./config/php/ /usr/local/etc/php/conf.d/

# add NGINX config
COPY config/nginx/*.conf /etc/nginx/
COPY config/nginx/mime.types /etc/nginx/mime.types
COPY config/nginx/conf.d/ /etc/nginx/conf.d/ 

# add supervisord config
COPY config/supervisord/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create symlink for php8
RUN ln -s /usr/bin/php8 /usr/bin/php

# Make sure files/folders needed by the processes are accessable when they run under the nobody user
RUN chown -R nobody:nobody /var/www/html && \
    chown -R nobody:nobody /run && \
    chown -R nobody:nobody /var/lib/nginx && \
    chown -R nobody:nobody /var/log/nginx

# Add application
WORKDIR /var/www/html
COPY --chown=nobody --from=COMPOSER_CHAIN /tmp/ddbgo/ .
RUN mv docker-php-entrypoint-drupal.sh /usr/local/bin/docker-php-entrypoint-drupal; \
    chmod 775 /usr/local/bin/docker-php-entrypoint-drupal; \
    chmod +x /var/www/html/vendor/drush/drush/drush;
ENV PATH=${PATH}:/var/www/html/vendor/bin

# Switch to use a non-root user
USER nobody:nobody

ENTRYPOINT ["docker-php-entrypoint-drupal"]

# Expose the port for nginx
EXPOSE 8080

# supervisord starts nginx & php-fpm
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
