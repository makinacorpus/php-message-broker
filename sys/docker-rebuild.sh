#!/bin/bash
if [! -f "./composer.phar"]; then
    wget https://getcomposer.org/download/latest-stable/composer.phar
fi
APP_DIR="`dirname $PWD`" docker-compose -p mmessagebroker up -d --build --remove-orphans --force-recreate

