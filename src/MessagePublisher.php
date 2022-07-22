<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker;

use MakinaCorpus\Message\Envelope;

/**
 * Publishes messages, whatever will be the routing.
 */
interface MessagePublisher
{
    /**
     * Send message.
     *
     * @param null|string $routingKey
     *   Hint for message routing, if underlaying broker supports it. For AMQP
     *   0.9 and 0.10 this would allow the AMQP broker to route the message to
     *   the right queue. For others, I guess it depends. AMQP 1.0 relies on the
     *   fact that an upper layer must do the job.
     */
    public function dispatch(Envelope $envelope, ?string $routingKey = null): void;
}
