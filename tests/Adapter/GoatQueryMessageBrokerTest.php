<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Tests\Adpater;

use Goat\Query\Expression\ValueExpression;
use Goat\Runner\Runner;
use Goat\Runner\Testing\TestDriverFactory;
use MakinaCorpus\MessageBroker\MessageConsumerFactory;
use MakinaCorpus\MessageBroker\MessagePublisher;
use MakinaCorpus\MessageBroker\Adapter\GoatQuery\GoatQueryMessageConsumerFactory;
use MakinaCorpus\MessageBroker\Adapter\GoatQuery\GoatQueryMessagePublisher;
use MakinaCorpus\MessageBroker\Tests\Adapter\AbstractMessageBrokerTest;

final class GoatQueryMessageBrokerTest extends AbstractMessageBrokerTest
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * {@inheritdoc}
     */
    protected function createTestData(Runner $runner, ?string $schema): void
    {
        $schema = $schema ?? "public";

        $runner->execute(
            <<<SQL
            CREATE TABLE IF NOT EXISTS "{$schema}"."message_broker" (
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
            CREATE TABLE IF NOT EXISTS "{$schema}"."message_broker_dead_letters" (
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
    protected function createItemInQueue(MessagePublisher $messageBroker, array $data): void
    {
        \assert($messageBroker instanceof GoatQueryMessagePublisher);

        $runner = (\Closure::bind(fn () => $messageBroker->runner, null, GoatQueryMessagePublisher::class))();

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
     * Create message broker instance that will be tested.
     */
    protected function createMessagePublisher(TestDriverFactory $factory): MessagePublisher
    {
        return new GoatQueryMessagePublisher($factory->getRunner(), $this->createSerializer(), ['schema' => $factory->getSchema()]);
    }

    /**
     * Create message broker instance that will be tested.
     */
    protected function createMessageConsumerFactory(TestDriverFactory $factory): MessageConsumerFactory
    {
        return new GoatQueryMessageConsumerFactory($factory->getRunner(), $this->createSerializer(), ['schema' => $factory->getSchema()]);
    }
}
