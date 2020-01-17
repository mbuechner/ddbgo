#!/bin/sh
set +e
echo "Start Drupal Update DB..."
/var/www/html/vendor/bin/drush --root /var/www/html/web updatedb
echo "Start Drupal Cache Rebuild..."
/var/www/html/vendor/bin/drush --root /var/www/html/web cache-rebuild

set -e
docker-php-entrypoint

exec "$@"