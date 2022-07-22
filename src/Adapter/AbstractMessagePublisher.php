<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Adapter;

use MakinaCorpus\Message\Envelope;
use MakinaCorpus\Message\Property;
use MakinaCorpus\MessageBroker\MessagePublisher;
use MakinaCorpus\Message\Identifier\MessageIdFactory;
use MakinaCorpus\Normalization\NameMap;
use MakinaCorpus\Normalization\Serializer;
use MakinaCorpus\Normalization\NameMap\NameMapAware;
use MakinaCorpus\Normalization\NameMap\NameMapAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

/**
 * Abstract implementation that carries all the logic.
 */
abstract class AbstractMessagePublisher implements MessagePublisher, LoggerAwareInterface, NameMapAware
{
    use LoggerAwareTrait;
    use NameMapAwareTrait;

    private string $contentType;
    private string $defaultQueue;
    private array $options;
    private Serializer $serializer;

    public function __construct(Serializer $serializer, array $options = [])
    {
        $this->contentType = ($options['content_type'] ?? Property::DEFAULT_CONTENT_TYPE);
        $this->logger = new NullLogger();
        $this->options = $options;
        $this->serializer = $serializer;

        if ($defaultQueue = ($options['default_queue'] ?? null)) {
            $this->defaultQueue = $defaultQueue;
        } else {
            $this->defaultQueue = 'default';
        }
    }

    /**
     * Send new message.
     *
     * This method must be atomic on the driver.
     */
    protected abstract function doSend(Envelope $envelope, string $serializedBody, string $routingKey): void;

    /**
     * {@inheritdoc}
     */
    public function dispatch(Envelope $envelope, ?string $routingKey = null): void
    {
        $messageId = MessageIdFactory::generate();

        // Reset message id if there is one, for the simple and unique reason
        // that the application could arbitrary resend a message as a new
        // message at anytime, and message identifier is the unique key.
        $envelope->withMessageId($messageId);

        $message = $envelope->getMessage();

        if (!$envelope->hasProperty(Property::MESSAGE_TYPE)) {
            $envelope->withProperties([Property::MESSAGE_TYPE => $this->getNameMap()->fromPhpType(\get_class($message), NameMap::TAG_COMMAND)]);
        }

        $contentType = $envelope->getProperty(Property::CONTENT_TYPE);
        if (!$contentType) {
            // For symfony/messenger compatibility.
            $contentType = $envelope->getProperty('Content-Type', $this->contentType);

            if (!$contentType) {
                // We have to fallback onto something.
                $contentType = 'application/json';
            }

            $envelope->withProperties([Property::CONTENT_TYPE => $contentType]);
        }

        // Set outgoing queue from input.
        if (!$routingKey) {
            $routingKey = $envelope->getProperty(Property::ROUTING_KEY, $this->defaultQueue);
        }

        $this->doSend($envelope, $this->serializer->serialize($message, $contentType), $routingKey);
    }
}
