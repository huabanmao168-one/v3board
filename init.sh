#!/bin/bash

set -e

composer_download=$(mktemp ./composer.phar.XXXXXX)
trap 'rm -f -- "$composer_download"' EXIT
wget https://github.com/composer/composer/releases/latest/download/composer.phar -O "$composer_download"
mv -- "$composer_download" composer.phar
if ! php composer.phar install -vvv; then
    echo "Composer failed. Database and Webman steps were skipped." >&2
    exit 1
fi

php_main_version=$(php -v | head -n 1 | cut -d ' ' -f 2 | cut -d '.' -f 1)
if [ $php_main_version -ge 8 ]; then
    php composer.phar require joanhey/adapterman || exit 1
fi

php artisan v2board:install

if [ -f "/etc/init.d/bt" ]; then
  chown -R www $(pwd);
fi
