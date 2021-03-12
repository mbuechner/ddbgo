#!/bin/sh
set +e;

MAINTENANCE_ON_STARTUP="${MAINTENANCE_ON_STARTUP:-yes}"

if [ "$MAINTENANCE_ON_STARTUP" = "no" ]; then
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
