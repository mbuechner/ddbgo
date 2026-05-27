<?php

/**
 * This file is included very early. See autoload.files in composer.json and
 * https://getcomposer.org/doc/04-schema.md#files
 */

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidPathException;

/**
 * Load any .env file. See /.env.example.
 *
 * Use the unsafe variant to also populate getenv()/putenv(). Drupal settings
 * in this project read from getenv(), and Drush CLI should behave the same on
 * Linux and Windows.
 */
$dotenv = Dotenv::createUnsafeImmutable(__DIR__);
try {
  $dotenv->load();
}
catch (InvalidPathException $e) {
  // Do nothing. Production environments rarely use .env files.
}
