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
