# See https://github.com/composer/docker/blob/master/2.1/Dockerfile
# The following is identical, except the use of PHP 7.
FROM composer:2 AS COMPOSER_CHAIN
MAINTAINER Michael Büchner <m.buechner@dnb.de>
# the following is for DDBgo
RUN apk add libpng libpng-dev libjpeg-turbo-dev libwebp-dev zlib-dev libxpm-dev; \
	docker-php-ext-configure gd --with-jpeg --with-freetype; \
	docker-php-ext-install gd;
COPY / /tmp/ddbgo
WORKDIR /tmp/ddbgo
RUN composer install --no-dev

# Add git tag version to status page
RUN sed -i -e "s:{{version}}:$(git describe --tags):g" web/modules/custom/ddbgo_workarounds/ddbgo_workarounds.install; \
	sed -i -e "s:{{commitid}}:$(git rev-parse HEAD):g" web/modules/custom/ddbgo_workarounds/ddbgo_workarounds.install;
RUN rm -rf .git/

# from https://github.com/docker-library/drupal/blob/master/8.7/apache/Dockerfile
FROM php:7.4-apache
MAINTAINER Michael Büchner <m.buechner@dnb.de>
RUN set -eux; \
	if command -v a2enmod; then \
		a2enmod rewrite; \
	fi; \
	savedAptMark="$(apt-mark showmanual)"; \
	apt-get update; \
	apt-get install -y --no-install-recommends \
		libfreetype6-dev \
		libjpeg62-turbo-dev \
		libpng-dev \
		libpq-dev \
		libzip-dev \
		libpcre3-dev; \
	docker-php-ext-configure gd \
		--with-freetype \
		--with-jpeg; \
	docker-php-ext-install -j "$(nproc)" \
		gd \
		opcache \
		pdo_mysql \
		pdo_pgsql \
		zip; \
	pecl install uploadprogress apcu oauth; \
	docker-php-ext-enable uploadprogress apcu oauth; \
	apt-mark auto '.*' > /dev/null; \
	apt-mark manual $savedAptMark; \
	ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so \
		| awk '/=>/ { print $3 }' \
		| sort -u \
		| xargs -r dpkg-query -S \
		| cut -d: -f1 \
		| sort -u \
		| xargs -rt apt-mark manual; \
	apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false;

RUN echo "LISTEN 8080" > /etc/apache2/ports.conf; \
    { \
		echo "opcache.file_update_protection=0"; \
		echo "opcache.validate_timestamps=0"; \
		echo "opcache.interned_strings_buffer=16"; \
		echo "opcache.memory_consumption=128"; \
		echo "opcache.max_accelerated_files=4000"; \
		echo "opcache.max_wasted_percentage=10"; \
		echo "opcache.revalidate_freq=60"; \
	} > /usr/local/etc/php/conf.d/opcache-recommended.ini; \
    { \
		echo "apc.enabled=1"; \
		echo "apc.file_update_protection=2"; \ 
		echo "apc.optimization=0"; \ 
		echo "apc.shm_size=256M"; \ 
		echo "apc.include_once_override=0"; \ 
		echo "apc.shm_segments=1"; \ 
		echo "apc.ttl=7200"; \ 
		echo "apc.user_ttl=7200"; \ 
		echo "apc.gc_ttl=3600"; \ 
		echo "apc.num_files_hint=1024"; \ 
		echo "apc.enable_cli=0"; \ 
		echo "apc.max_file_size=5M"; \ 
		echo "apc.cache_by_default=1"; \ 
		echo "apc.use_request_time=1"; \ 
		echo "apc.slam_defense=0"; \ 
		echo "apc.mmap_file_mask=/tmp/apc.XXXXXX"; \ 
		echo "apc.stat_ctime=0"; \ 
		echo "apc.canonicalize=1"; \ 
		echo "apc.write_lock=1"; \ 
		echo "apc.report_autofilter=0"; \ 
		echo "apc.rfc1867=0"; \ 
		echo "apc.rfc1867_prefix =upload_"; \ 
		echo "apc.rfc1867_name=APC_UPLOAD_PROGRESS"; \ 
		echo "apc.rfc1867_freq=0"; \ 
		echo "apc.rfc1867_ttl=3600"; \ 
		echo "apc.lazy_classes=0"; \ 
		echo "apc.lazy_functions=0"; \
	} > /usr/local/etc/php/conf.d/apcu-caching.ini; \
    { \
		echo "upload_max_filesize = 128M"; \
		echo "post_max_size = 128M"; \
		echo "memory_limit = 512M"; \
		echo "max_execution_time = 600"; \
		echo "max_input_vars = 5000"; \
	} > /usr/local/etc/php/conf.d/0-upload_large_dumps.ini; \
	{ \
		echo "<VirtualHost *:8080>"; \
		echo "  ServerAdmin m.buechner@dnb.de"; \
		echo "  DocumentRoot /var/www/html/web"; \
		echo "  ErrorLog /dev/stderr"; \
		echo "  CustomLog /dev/stdout combined"; \
		echo "</VirtualHost>"; \
	} > /etc/apache2/sites-enabled/000-default.conf;

WORKDIR /var/www/html
COPY --from=COMPOSER_CHAIN /tmp/ddbgo/ .
COPY docker-php-entrypoint-drupal.sh /usr/local/bin/docker-php-entrypoint-drupal

RUN chmod 775 /usr/local/bin/docker-php-entrypoint-drupal; \
	chown -R www-data:www-data web/sites web/modules web/themes web/tmp; \
	chmod +x /var/www/html/vendor/drush/drush/drush;
RUN find web \( -type d -exec chmod 755 {} + \) -o \( -type f -exec chmod 644 {} + \)

# Clean system
RUN apt-get clean; \
	rm -rf /var/lib/apt/lists/*

ENTRYPOINT ["docker-php-entrypoint-drupal"]

HEALTHCHECK --interval=1m --timeout=3s CMD curl --fail http://localhost:8080/ || exit 1

EXPOSE 8080
CMD ["apache2-foreground"]
