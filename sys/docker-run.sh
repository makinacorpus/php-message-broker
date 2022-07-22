#!/bin/bash

if [[ ! -z "$DEBUG" ]]; then
    # Find host IP.
    if ( ip -4 route list match 0/0 &>/dev/null );then
        HOST_IP=`ip -4 route list match 0/0 | awk '{print $3}'`
    fi
    if [[ -z "$HOST_IP" ]]; then
        echo "Cannot fetch host ip."
        exit
    fi

    echo "Running tests on PHP 8.1 with Xdebug enabled."
    APP_DIR="`dirname $PWD`" docker-compose -p mmessagebroker run \
        -e XDEBUG_CONFIG="mode=debug start_with_request=yes client_host=${HOST_IP}" \
        -e XDEBUG_MODE=debug \
        -e XDEBUG_TRIGGER=1 \
        php81 vendor/bin/phpunit "$@"
    exit
fi

echo "Running tests on PHP 8.0"
rm ../composer.lock
APP_DIR="`dirname $PWD`" docker-compose -p mmessagebroker run php80 composer install
APP_DIR="`dirname $PWD`" docker-compose -p mmessagebroker run php80 vendor/bin/phpunit "$@"

echo "Running tests on PHP 8.1"
rm ../composer.lock
APP_DIR="`dirname $PWD`" docker-compose -p mmessagebroker run php81 composer install
APP_DIR="`dirname $PWD`" docker-compose -p mmessagebroker run php81 vendor/bin/phpunit "$@"

