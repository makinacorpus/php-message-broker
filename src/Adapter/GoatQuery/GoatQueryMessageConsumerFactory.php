<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Adapter\GoatQuery;

use Goat\Runner\Runner;
use MakinaCorpus\MessageBroker\MessageConsumer;
use MakinaCorpus\MessageBroker\MessageConsumerFactory;
use MakinaCorpus\Normalization\Serializer;
use MakinaCorpus\Normalization\NameMap\NameMapAwareTrait;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

/**
 * This is a PostgreSQL implementation, which uses PostgresSQL only dialect.
 */
final class GoatQueryMessageConsumerFactory implements MessageConsumerFactory
{
    use LoggerAwareTrait;
    use NameMapAwareTrait;

    private Runner $runner;
    private array $options;
    private Serializer $serializer;

    public function __construct(Runner $runner, Serializer $serializer, array $options = [])
    {
        $this->runner = $runner;
        $this->logger = new NullLogger();
        $this->options = $options;
        $this->serializer = $serializer;
    }

    /**
     * @param string[] $queues
     */
    public function createConsumer(?array $queues = null): MessageConsumer
    {
        $options = $this->options;
        $options['queue'] = $queues;

        $ret = new GoatQueryMessageConsumer($this->runner, $this->serializer, $options);
        $ret->setLogger($this->logger);
        $ret->setNameMap($this->getNameMap());

        return $ret;
    }
}

