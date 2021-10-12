#!/bin/sh
set +e;

UPDATEDB_ON_STARTUP="${UPDATEDB_ON_STARTUP:-no}";
CACHEREBUILD_ON_STARTUP="${CACHEREBUILD_ON_STARTUP:-no}";
HTPASSWD_GREETING="${HTPASSWD_GREETING:-Sie greifen auf ein Testsystem der DDB zu. Bitte geben als Benutzer 'testsystem' und als Passwort ebenfalls 'testsystem' ein.}";
# HTPASSWD_USER -> HTTP Basic Auth User
# HTPASSWD_PWD -> HTTP Basic Auth Password

if [ -n "${HTPASSWD_USER}" ] && [ -n "${HTPASSWD_PWD}" ]; then

  echo "Setting HTTP Auth for nginx...";
  printf '%s:%s\n' "${HTPASSWD_USER}" "$(openssl passwd -crypt "${HTPASSWD_PWD}")" > /etc/nginx/.authpasswd;
  {
    echo "# configuration file /etc/nginx/auth.conf:";
    echo "";
    echo "auth_basic \"${HTPASSWD_GREETING}\";";
    echo "auth_basic_user_file /etc/nginx/.authpasswd;";
  } > /etc/nginx/auth.conf;
fi;

if [ "$UPDATEDB_ON_STARTUP" = "yes" ]; then
  echo "Start Drupal Update DB...";
  /var/www/html/vendor/bin/drush --root /var/www/html/web updatedb;
fi;

if [ "$CACHEREBUILD_ON_STARTUP" = "yes" ]; then
  echo "Start Drupal Cache Rebuild...";
  /var/www/html/vendor/bin/drush --root /var/www/html/web cache-rebuild;
fi;

set -e;

docker-php-entrypoint;

exec "$@";
