k#!/bin/sh
set +e;

if [ ${MAINTENANCE_ON_STARTUP+x} = "no" ]; then
        echo "NOT doing Drupal Update DB and Cache Rebuild...";
else
        echo "Start Drupal Update DB...";
        /var/www/html/vendor/bin/drush --root /var/www/html/web updatedb;
        echo "Start Drupal Cache Rebuild...";
        /var/www/html/vendor/bin/drush --root /var/www/html/web cache-rebuild;
fi;

set -e;

docker-php-entrypoint;

exec "$@";

