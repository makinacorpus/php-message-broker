<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker;

use MakinaCorpus\Message\Envelope;

/**
 * Consume messages from a single or more queues.
 */
interface MessageConsumer
{
    /**
     * Fetch next awaiting message from the queue.
     */
    public function get(): ?Envelope;

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
