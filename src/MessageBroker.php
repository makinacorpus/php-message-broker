<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker;

use MakinaCorpus\Message\Envelope;

/**
 * Simple message broker interface, with some additional business logic
 * compared to symfony/messenger transports.
 *
 * This implementation will handle message headers and properties as first
 * class citizens and will use it for (de)serialization purpose, and a few
 * other transport and routing domain logic.
 *
 * API is almost identical to symfony/messenger transport one and message bus
 * altogether, with the exception that we enfore Envelope type on every
 * method, as the common protocol.
 */
interface MessageBroker
{
    /**
     * Fetch next awaiting message from the queue.
     */
    public function get(): ?Envelope;

    /**
     * Send message.
     */
    public function dispatch(Envelope $envelope): void;

    /**
     * Acknowledges a single message.
     */
    public function ack(Envelope $envelope): void;

    /**
     * Reject or requeue a single message.
     *
     * Re-queing will be decided using envelope properties.
     */
    public function reject(Envelope $envelope, ?\Throwable $e = null): void;
}
