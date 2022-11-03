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
    private bool $useListen = false;
    private string $listenChannel = 'goat_message_broker';

    public function __construct(Runner $runner, Serializer $serializer, array $options = [])
    {
        parent::__construct($serializer, $options);

        $this->runner = $runner;
        $this->schema = $options['schema'] ?? 'public';

        if ($options['listen_enabled'] ?? false) {
            if ('pgsql' === ($driver = $runner->getDriverName())) {
                $this->useListen = true;
            } else {
                \trigger_error(\sprintf("'listen_enabled' option is set to true using driver '%s', but it only is supported by 'pgsql'.", $driver), E_USER_WARNING);
            }
        }

        if ($value = ($options['listen_channel'] ?? null)) {
            $this->listenChannel = $value;
        }
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

        // @todo Explore the pertinence of using a trigger here.
        if ($this->useListen) {
            $this->runner->perform("SELECT pg_notify(?, ?)", [new ValueExpression($this->listenChannel), new ValueExpression($routingKey, 'text')]);
        }
    }
}
