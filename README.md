![PHP Composer](https://github.com/mbuechner/ddbgo/workflows/PHP%20Composer/badge.svg) ![Docker](https://github.com/mbuechner/ddbgo/workflows/Docker/badge.svg)

# DDBgo
DDBgo ist eine Datenbank der [Deutsche Digitalen Bibliothek](https://www.deutsche-digitale-bibliothek.de/) auf Basis des Content Management Systems [Drupal](https://www.drupal.org/), die Informationen zu Kultur- und Wissenseinrichtungen, Beständen, Aggregatoren und AnsprechpartnerInnen bereithält. Es können alle relevanten Informationen, die die Entitäten betreffen, eingetragen und nachgesehen werden. Zusätzlich werden die Beziehungen der Entitäten zueinander dargestellt. DDBgo ist ein internes Verwaltungs- und Arbeitsinstrument der [Servicestelle und der Fachstellen der Deutschen Digitalen Bibliothek](https://pro.deutsche-digitale-bibliothek.de/).

<p align="center">
  <a href="images/DDBgo_Screenshot.png"><img src="https://raw.githubusercontent.com/mbuechner/ddbgo/master/images/DDBgo_Screenshot.png" width="512" title="DDBgo Screenshot"></a>
</p>

## Drupal modules
### DDBgo Work-a-rounds
See folder [web/modules/custom/ddbgo_workarounds/](web/modules/custom/ddbgo_workarounds/).
### DDBgo Cron Job
See folder [web/modules/custom/ddbgo_cj/](web/modules/custom/ddbgo_cj/).
### DDBgo Search
See folder [web/modules/custom/ddbgo_search](web/modules/custom/ddbgo_search/).
## Drupal patches
See folder [patches/](patches/).
1. [Changed_tokenSeparator_in_Select2_module.patch](patches/Changed_tokenSeparator_in_Select2_module.patch)
2. [DDBgo-improvements_in_unique_field_ajax_module.patch](patches/DDBgo-improvements_in_unique_field_ajax_module.patch)
   
   Discussion at: https://www.drupal.org/project/unique_field_ajax/issues/2932042#comment-12950248

## Composer
DDBgo is developed using the package manager [Composer](https://getcomposer.org/). Please make sure you have installed it correctly. All Composer commands should be executed within the folder with the file [composer.json](composer.json).

### Run DDBgo localy
1. Run `composer install` to install the project in your local directory
2. Add file `.env` with the following configuration for Drupal's MySQL database connection (example file [.env.example](.env.example)).
   ```
   MYSQL_DATABASE=ddbgodb
   MYSQL_HOSTNAME=localhost
   MYSQL_PASSWORD=ddbgopw
   MYSQL_PORT=3306
   MYSQL_USER=ddbgouser
   HASH_SALT=MY_SECRET_SALT
   UPDATE_FREE_ACCESS=FALSE
   FILE_PUBLIC_PATH=sites/default/files
   TRUSTED_HOST_PATTERNS="^localhost\$, ^127.0.0.1\$"
   TMP=/tmp
   ```
3. Use [Drush](https://www.drush.org/) command to run local server: `vendor/bin/drush rs`
4. Access via  http://127.0.0.1:8888

If you start with a blank database you need to import all configuration with [Drush](https://www.drush.org/), which is stored in the folder [config/sync](config/sync). Therefor run `vendor/drush/drush/drush config:import`.

### Composer project maintenance
1. Find update-able packages: 
   `composer outdated --direct`
2. Show available package versions for a package: 
   `composer show --all drupal/facets`
3. Install new packages: 
   `composer require drupal/facets` or `composer require 'drupal/facets:^1.4'`
4. Update specific package: 
   `composer update drupal/facets` or `composer update 'drupal/facets:^1.4'`
5. Update all packages to newest version: 
   `composer update`
6. Update to new major version: 
   `composer require drupal/core-recommended:^8.8 --update-with-dependencies --no-plugins`

### Drush commands
1. Update Drupal's database: 
   `vendor/drush/drush/drush updatedb`
2. Rebuild cache: 
   `vendor/drush/drush/drush cr`
3. Runs PHP's built-in HTTP server (for development only): 
   `vendor/drush/drush/drush rs`
4. Export all configuration to folder [config/sync](config/sync): 
   `vendor/drush/drush/drush config:export`.
5. Import all configuration from folder [config/sync](config/sync): 
   `vendor/drush/drush/drush config:import`.

## Docker
Yes, there's a docker container for DDBgo available at GitHub: https://github.com/mbuechner/ddbgo/pkgs/container/ddbgo%2Fddbgo
```
docker pull ghcr.io/mbuechner/ddbgo/ddbgo:tagged
```
## Container build
1. Checkout GitHub repository:
   `git clone https://github.com/mbuechner/ddbgo`
2. Go into folder:
  `cd ddbgo`
3. Run
   `docker build -t ddbgo .`
4. Start container with:
   `docker run -d -p 8080 -P ddbgo`
5. Open browser:
   http://localhost:8080/
   
## Cron job
It's important to run Drupal's Cron job regularly. It's recommended to trigger it *every minute* with a forbidden concurrency from an external cron job. The [Drush command](https://docs.drush.org/en/8.x/cron/) is a simple way to execute Drupal's cron job.
Run:
```
> /var/www/html/vendor/bin/drush --root /var/www/html/web/ --quiet cron > /dev/stdout
```
Cron task:
```
*/1 * * * * /var/www/html/vendor/bin/drush --root /var/www/html/web/ --quiet cron > /dev/stdout
```
More information about cron at the [Drupal documentation](https://www.drupal.org/docs/administering-a-drupal-site/cron-automated-tasks/cron-automated-tasks-overview).
