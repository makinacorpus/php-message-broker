<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker;

/**
 * From a single connexion/backend, create consumer plugged to queues.
 */
interface MessageConsumerFactory
{
    /**
     * @param string[] $queues
     *   Null means ['default'].
     */
    public function createConsumer(?array $queues = null): MessageConsumer;
}
