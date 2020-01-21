#!/bin/sh
set +e
echo "Start Drupal Update DB..."
/var/www/html/vendor/bin/drush --root /var/www/html/web updatedb
echo "Start Drupal Cache Rebuild..."
/var/www/html/vendor/bin/drush --root /var/www/html/web cache-rebuild

set -e
echo "Export Environment variables for Cron job..."
printenv | sed 's/^\(.*\)$/export \1/g' | sed 's/=/="/' | sed 's/$/"/' > /root/ddbgoenv.sh
chmod +x /root/ddbgoenv.sh
echo "Start Cron service..."
service cron start

docker-php-entrypoint

exec "$@"