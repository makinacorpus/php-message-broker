#!/bin/bash
APP_DIR="`dirname $PWD`" docker-compose -p mmessagebroker up -d --build --remove-orphans --force-recreate
