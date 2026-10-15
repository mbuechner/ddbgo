#!/bin/sh
set -eu

UPDATEDB_ON_STARTUP="${UPDATEDB_ON_STARTUP:-no}";
CONFIG_IMPORT_ON_STARTUP="${CONFIG_IMPORT_ON_STARTUP:-no}";
CACHEREBUILD_ON_STARTUP="${CACHEREBUILD_ON_STARTUP:-no}";

if [ "$UPDATEDB_ON_STARTUP" = "yes" ]; then
  echo "Start Drupal Update DB...";
  /var/www/html/vendor/bin/drush -y --root /var/www/html/web updatedb;
fi;

if [ "$CONFIG_IMPORT_ON_STARTUP" = "yes" ]; then
  echo "Start Drupal Config Import...";
  /var/www/html/vendor/bin/drush -y --root /var/www/html/web config:import;
fi;

if [ "$CACHEREBUILD_ON_STARTUP" = "yes" ]; then
  echo "Start Drupal Cache Rebuild...";
  /var/www/html/vendor/bin/drush -y --root /var/www/html/web cache-rebuild;
fi;
