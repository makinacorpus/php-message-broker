# Message broker

Opiniated and simple message broker interface along with a PostgreSQL
working and stable implementation.

This package alone may probably not useful for many, please consider using it
along the `makinacorpus/corebus` package, which add meaningful opiniated bus
logic around.

This implementation was extracted from `makinacorpus/goat` and is stable.

# Setup

First of all, install this package:

```sh
composer install makinacorpus/message-broker
```

It is also recommended to chose an UUID implementation:

```sh
composer install ramsey/uuid
```

Or:

```sh
composer install symfony/uid
```

Our favorite remains `ramsey/uuid`.

## Symfony

Start by installing the `makinacorpus/goat-query-bundle` Symfony bundle if you
with to use the PostgreSQL implementation:

```sh
composer install makinacorpus/goat-query-bundle
```

And configure it as documented.

Then register the bundle into your `config/bundles.php` file:

```php
<?php

return [
    // ... Your other bundles.
    MakinaCorpus\MessageBroker\Bridge\Symfony\MessageBrokerBundle::class => ['all' => true],
];
```

**Important note**: using Symfony and the `goat-query` implementation, the
message broker will default on the `goat.runner.default` default database
connection.

If you need to setup another connection, simply add into the
`config/goat_query.yaml` file:

```yaml
parameters:
    #
    # Overrides the one from this bundle.
    #
    goat.runner.message_broker:
        alias: goat.runner.my_message_broker_connection

goat_query:
    runner:
        # ... Your other connections, then:

        #
        # Your dedicated connection.
        #
        my_message_broker_connection:
            url: '%env(resolve:DATABASE_URL_MESSAGE_BROKER)%'
```

This library may evolve later to allow multiple message brokers to co-exist
in container, one for each queue, case in which environment variables will
become the primary place for configuring.

**Another important note**: using Symfony and the `goat-query` implementation,
message broker instance will always be registered with the queue named
`default`.

This will be configurable someday, it just isn't right now.

## Standalone

This is not documented yet, but basically only thing you need to do is to
create an instance implementing `MessageBroker`.

## PostgreSQL schema

If you are going to use the PostgreSQL implementation, please create the
necessary database tables in your default schema, please see the
`src/Adapter/schema/message-broker.pg.sql` file.

# Usage

This is not documented yet.

# Run tests

A docker environement with various containers for various PHP versions is
present in the `sys/` folder. For tests to work in all PHP versions, you
need to run `composer update --prefer-lowest` in case of any failure.

```sh
composer install
composer update --prefer-lowest
cd sys/
./docker-rebuild.sh # Run this only once
./docker-run.sh
```

Additionnaly generate coverage report:

```sh
./docker-coverage.sh
```

HTML coverage report will be generated in `coverage` folder.
