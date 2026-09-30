<?php

/*
 * PHP 8.5 deprecated PDO::MYSQL_ATTR_SSL_CA, which Laravel's own
 * vendor/laravel/framework/config/database.php still references, filling
 * the log with deprecation notices. This swaps in the new constant after
 * every `composer install` / `composer update`. Does nothing on PHP < 8.5
 * (e.g. a production server on 8.2/8.3).
 *
 * Kept in the project (not under vendor/) so it also runs with
 * `composer install --no-dev` on a production server, where dev packages
 * aren't installed.
 */

if (PHP_VERSION_ID < 80500) {
    exit(0);
}

$file = __DIR__.'/../vendor/laravel/framework/config/database.php';

if (! file_exists($file)) {
    exit(0);
}

file_put_contents($file, str_replace(
    'PDO::MYSQL_ATTR_SSL_CA',
    '(PHP_VERSION_ID >= 80500 ? Pdo\\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA)',
    file_get_contents($file),
));
