<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Adapter;

use MakinaCorpus\Message\BrokenEnvelope;
use MakinaCorpus\Message\Envelope;
use MakinaCorpus\Message\Property;
use MakinaCorpus\MessageBroker\MessageBroker;
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
abstract class AbstractMessageBroker implements MessageBroker, LoggerAwareInterface, NameMapAware
{
    use LoggerAwareTrait;
    use NameMapAwareTrait;

    const PROP_SERIAL = 'x-serial';

    private string $contentType;
    private string $queue;
    private array $options;
    private Serializer $serializer;

    public function __construct(Serializer $serializer, array $options = [])
    {
        $this->contentType = $options['content_type'] ?? Property::DEFAULT_CONTENT_TYPE;
        $this->logger = new NullLogger();
        $this->options = $options;
        $this->queue = $options['queue'] ?? 'default';
        $this->serializer = $serializer;
    }

    /**
     * Get next message.
     *
     * This method must be atomic on the driver.
     *
     * @return null|array
     *   Expected values are:
     *     - "id" (mixed): arbitrary message identifier from driver.
     *     - "serial" (int): arbitrary message serial number.
     *     - "body" (string): message body.
     *     - "headers" (array<string,string>): message properties and headers.
     *     - "retry_count" (null|int): number of retries attempted so far.
     */
    protected abstract function doGet(): ?array;

    /**
     * Send new message.
     *
     * This method must be atomic on the driver.
     */
    protected abstract function doSend(Envelope $envelope, string $serializedBody): void;

    /**
     * Send ACK for message.
     *
     * This method must be atomic on the driver.
     */
    protected abstract function doAck(Envelope $envelope): void;

    /**
     * Mark a message for retry (reject with retry).
     *
     * This method must be atomic on the driver.
     */
    protected abstract function doMarkForRetry(Envelope $envelope): void;

    /**
     * Mark the message as failed (reject).
     *
     * This method must be atomic on the driver.
     */
    protected abstract function doMarkAsFailed(Envelope $envelope, ?\Throwable $exception = null): void;

    /**
     * {@inheritdoc}
     */
    public function get(): ?Envelope
    {
        $data = $this->doGet();

        if (!$data) {
            return null;
        }

        $serial = (int) $data['serial'];

        try {
            if (\is_resource($data['body'])) { // Bytea
                $body = \stream_get_contents($data['body']);
            } else {
                $body = $data['body'];
            }

            // Restore necessary properties on which we are authoritative.
            $data['headers'][Property::MESSAGE_ID] = $data['id']->toString();
            $data['headers'][self::PROP_SERIAL] = (string) $serial;
            if ($data['retry_count']) {
                $data['headers'][Property::RETRY_COUNT] = (string) $data['retry_count'];
            }

            $type = $data['headers'][Property::MESSAGE_TYPE] ?? null;
            // Default to "application/json" is for backward compatibilty.
            // But ideally, the content-type header should always be set.
            $contentType = $data['headers'][Property::CONTENT_TYPE] ?? null;

            if ($contentType && $type) {
                $className = $this->getNameMap()->toPhpType($type, NameMap::TAG_COMMAND);
                try {
                    return Envelope::wrap($this->serializer->unserialize($className, $contentType, $body), $data['headers']);
                } catch (\Throwable $e) {
                    $this->markAsFailed($serial, $e);

                    // Serializer can throw any kind of exceptions, it can
                    // prove itself very unstable using symfony/serializer
                    // which doesn't like very much types when you are not
                    // working with doctrine entities.
                    // @todo place instrumentation over here.
                    return BrokenEnvelope::wrap($body, $data['headers']);
                }
            } else {
                return BrokenEnvelope::wrap($body, $data['headers']);
            }
        } catch (\Throwable $e) {
            $this->markAsFailed($serial, $e);

            throw new \RuntimeException('Error while fetching messages', 0, $e);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch(Envelope $envelope): void
    {
        $this->doDispatch($envelope);
    }

    /**
     * {@inheritdoc}
     */
    public function ack(Envelope $envelope): void
    {
        $this->doAck($envelope);
    }

    /**
     * {@inheritdoc}
     */
    public function reject(Envelope $envelope, ?\Throwable $exception = null): void
    {
        $serial = (int) $envelope->getProperty(self::PROP_SERIAL);

        if ($envelope->hasProperty(Property::RETRY_COUNT)) {
            // Having a count property means the caller did already set it,
            // we will not increment it ourself.
            $count = (int)$envelope->getProperty(Property::RETRY_COUNT, "0");
            $max = (int)$envelope->getProperty(Property::RETRY_MAX, (string)4);

            if ($count >= $max) {
                if ($serial) {
                    $this->markAsFailed($serial);
                }
                return; // Dead letter.
            }

            if (!$serial) {
                // This message was not originally delivered using this bus
                // so we cannot update an existing item in queue, create a
                // new one instead.
                // Note that this should be an error, it should not happen,
                // but re-queing it ourself is much more robust.
                $this->doDispatch($envelope, true);
                return;
            }

            $this->doMarkForRetry($envelope);
            return;
        }

        if ($serial) {
            $this->doMarkAsFailed($envelope);
        }
    }

    /**
     * Real implementation of dispatch.
     */
    private function doDispatch(Envelope $envelope, bool $keepMessageIdIfPresent = false): void
    {
        if ($keepMessageIdIfPresent) {
            $messageId = $envelope->getMessageId();
            if (!$messageId) {
                $messageId = MessageIdFactory::generate();
            }
        } else {
            $messageId = MessageIdFactory::generate();
        }

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

        $this->doSend($envelope, $this->serializer->serialize($message, $contentType));
    }

    /**
     * Get queue name.
     */
    protected function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * Normalize exception trace.
     */
    protected function normalizeExceptionTrace(\Throwable $exception): string
    {
        $output = '';
        do {
            if ($output) {
                $output .= "\n";
            }
            $output .= \sprintf("%s: %s\n", \get_class($exception), $exception->getMessage());
            $output .= $exception->getTraceAsString();
        } while ($exception = $exception->getPrevious());

        return $output;
    }
}
