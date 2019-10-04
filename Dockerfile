FROM thecodingmachine/php:7.2-v2-apache
MAINTAINER Michael Büchner <m.buechner@dnb.de>
VOLUME ./web/:/var/www/html/
ENV TEMPLATE_PHP_INI=production \
	APACHE_RUN_USER=www-data \
	APACHE_RUN_GROUP=www-data \
	CRON_USER=www-data \
	CRON_SCHEDULE=*/1 * * * * \
	CRON_COMMAND=vendor/bin/console wget -O - -q -t 1 http://127.0.0.1/cron/ClkfVwCYhJ9NLizpC6aLptMmu_U57vCQs5vvfRWawaqmi5SwOnZB8c4lbNN4K_khHncLE8tE_g
EXPOSE 80