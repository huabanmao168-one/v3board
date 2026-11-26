#!/bin/bash

set -e

if [ ! -d ".git" ]; then
  echo "Please deploy using Git."
  exit 1
fi

if ! command -v git &> /dev/null; then
    echo "Git is not installed! Please install git and try again."
    exit 1
fi

repository="${V3BOARD_UPDATE_REPOSITORY:-https://github.com/huabanmao168-one/v3board.git}"
git fetch "$repository" main || exit 1
git merge --ff-only FETCH_HEAD || exit 1
composer_download=$(mktemp ./composer.phar.XXXXXX)
trap 'rm -f -- "$composer_download"' EXIT
wget https://github.com/composer/composer/releases/latest/download/composer.phar -O "$composer_download"
mv -- "$composer_download" composer.phar
if ! php composer.phar update -vvv; then
    echo "Composer failed. Database and Webman steps were skipped." >&2
    exit 1
fi

php_main_version=$(php -v | head -n 1 | cut -d ' ' -f 2 | cut -d '.' -f 1)
if [ $php_main_version -ge 8 ]; then
    php composer.phar require joanhey/adapterman || exit 1
    if ! php -m | grep -q "pcntl"; then
        echo "Adding pcntl extension to cli-php.ini"
        sed -i '/extension=redis.so/a extension=pcntl.so' cli-php.ini
    fi
fi

php artisan v2board:update
php artisan view:clear

if [ "$php_main_version" -ge 8 ]; then
    echo "If you use Webman, restart it after the update."
fi

if [ -f "/etc/init.d/bt" ]; then
  chown -R www $(pwd);
fi
