<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Adapter\GoatQuery;

use Goat\Query\Expression\ValueExpression;
use Goat\Runner\Runner;
use MakinaCorpus\Message\Envelope;
use MakinaCorpus\MessageBroker\Adapter\AbstractMessagePublisher;
use MakinaCorpus\Normalization\Serializer;

/**
 * This is a PostgreSQL implementation, which uses PostgresSQL only dialect.
 */
final class GoatQueryMessagePublisher extends AbstractMessagePublisher
{
    private Runner $runner;
    private string $schema = 'public';

    public function __construct(Runner $runner, Serializer $serializer, array $options = [])
    {
        parent::__construct($serializer, $options);

        $this->runner = $runner;
        $this->schema = $options['schema'] ?? 'public';
    }

    /**
     * {@inheritdoc}
     */
    protected function doSend(Envelope $envelope, string $serializedBody, string $routingKey): void
    {
        $this->runner->perform(
            <<<SQL
            INSERT INTO "{$this->schema}"."message_broker"
                (id, queue, headers, body)
            VALUES
                (?::uuid, ?::string, ?::json, ?::bytea)
            SQL
           , [
               $envelope->getMessageId()->toString(),
               $routingKey,
               new ValueExpression($envelope->getProperties(), 'json'),
               $serializedBody,
           ]
        );
    }
}
