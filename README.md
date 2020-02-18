![Docker Image CI](https://github.com/mbuechner/ddbgo/workflows/Docker%20Image%20CI/badge.svg)

## Find update-able packages
composer outdated --direct

## Update to new makor version
composer require drupal/core-recommended:^8.8 --update-with-dependencies --no-plugins

##Show available module versions
composer show --all drupal/field_group

## update Drupal database
vendor/drush/drush/drush updatedb

##Rebuild cache 
vendor/drush/drush/drush cr

##Runs PHP's built-in http server for development.
vendor/drush/drush/drush rs 
