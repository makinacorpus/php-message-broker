<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Tests\Adpater;

use Goat\Query\Expression\ValueExpression;
use Goat\Runner\Runner;
use MakinaCorpus\MessageBroker\MessageBroker;
use MakinaCorpus\MessageBroker\Adapter\GoatQueryMessageBroker;
use MakinaCorpus\MessageBroker\Tests\Adapter\AbstractMessageBrokerTest;

final class GoatQueryMessageBrokerTest extends AbstractMessageBrokerTest
{
    /**
     * {@inheritdoc}
     */
    protected function createTestSchema(Runner $runner, string $schema)
    {
        $runner->execute(
            <<<SQL
            DROP TABLE IF EXISTS "{$schema}"."message_broker"
            SQL
        );

        $runner->execute(
            <<<SQL
            CREATE TABLE "{$schema}"."message_broker" (
                "id" uuid NOT NULL,
                "serial" bigserial NOT NULL,
                "queue" varchar(500) NOT NULL DEFAULT 'default',
                "created_at" timestamp NOT NULL DEFAULT now(),
                "consumed_at" timestamp DEFAULT NULL,
                "has_failed" bool DEFAULT false,
                "headers" jsonb NOT NULL DEFAULT '{}'::jsonb,
                "body" bytea NOT NULL,
                "error_code" bigint default null,
                "error_message" varchar(500) default null,
                "error_trace" text default null,
                "retry_count" bigint DEFAULT 0,
                "retry_at" timestamp DEFAULT NULL,
                PRIMARY KEY ("serial")
            );
            SQL
        );

        $runner->execute(
            <<<SQL
            DROP TABLE IF EXISTS "{$schema}"."message_broker_dead_letters"
            SQL
        );

        $runner->execute(
            <<<SQL
            CREATE TABLE "{$schema}"."message_broker_dead_letters" (
                "id" uuid NOT NULL,
                "serial" bigint,
                "queue" varchar(500) NOT NULL DEFAULT 'default',
                "created_at" timestamp NOT NULL DEFAULT now(),
                "consumed_at" timestamp DEFAULT NULL,
                "headers" jsonb NOT NULL DEFAULT '{}'::jsonb,
                "body" bytea NOT NULL,
                "error_code" bigint default null,
                "error_message" varchar(500) default null,
                "error_trace" text default null,
                PRIMARY KEY ("serial")
            );
            SQL
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function createItemInQueue(MessageBroker $messageBroker, array $data): void
    {
        \assert($messageBroker instanceof GoatQueryMessageBroker);

        $runner = (\Closure::bind(fn () => $messageBroker->runner, null, GoatQueryMessageBroker::class))();

        $runner
            ->getQueryBuilder()
            ->insert('message_broker')
            ->values([
                'id' => $data['id'],
                'headers' => new ValueExpression($data['headers'], 'json'),
                'body' => $data['body'],
            ])
            ->execute()
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function createMessageBroker(Runner $runner, string $schema): MessageBroker
    {
        $this->createTestSchema($runner, $schema);

        return new GoatQueryMessageBroker($runner, $this->createSerializer());
    }
}
