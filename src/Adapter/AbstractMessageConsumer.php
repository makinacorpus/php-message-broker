<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Adapter;

use MakinaCorpus\Message\BrokenEnvelope;
use MakinaCorpus\Message\Envelope;
use MakinaCorpus\Message\Property;
use MakinaCorpus\MessageBroker\MessageConsumer;
use MakinaCorpus\Message\Identifier\MessageId;
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
abstract class AbstractMessageConsumer implements MessageConsumer, LoggerAwareInterface, NameMapAware
{
    use LoggerAwareTrait;
    use NameMapAwareTrait;

    private string $contentType;
    private array $queue;
    private array $options;
    private Serializer $serializer;

    public function __construct(Serializer $serializer, array $options = [])
    {
        $this->contentType = $options['content_type'] ?? Property::DEFAULT_CONTENT_TYPE;
        $this->logger = new NullLogger();
        $this->options = $options;
        $this->serializer = $serializer;

        if ($queue = $options['queue']) {
            if (\is_array($queue)) {
                $this->queue = $queue;
            } else {
                $this->queue = [$queue];
            }
        } else {
            $this->queue = ['default'];
        }
    }

    /**
     * Get next message.
     *
     * This method must be atomic on the driver.
     *
     * @return null|array
     *   Expected values are:
     *     - "id" (mixed): arbitrary message identifier from driver.
     *     - "serial" (int): backward compatibility, you may omit it.
     *     - "body" (string): message body.
     *     - "headers" (array<string,string>): message properties and headers.
     *     - "retry_count" (null|int): number of retries attempted so far.
     */
    protected abstract function doGet(): ?array;

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
     *
     * We cannot give you an envelope here, because it might have failed before
     * constructing it.
     */
    protected abstract function doMarkAsFailed(MessageId $id, ?\Throwable $exception = null): void;

    /**
     * {@inheritdoc}
     */
    public function get(): ?Envelope
    {
        $data = $this->doGet();

        if (!$data) {
            return null;
        }

        try {
            $id = MessageIdFactory::create($data['id']);

            if (\is_resource($data['body'])) { // Bytea
                $body = \stream_get_contents($data['body']);
            } else {
                $body = $data['body'];
            }

            // Kept for backward compatibility, but in real life, we do
            // not really care about this serial.
            if (isset($data['serial'])) {
                $data['headers']['x-serial'] = $data['serial'];
            }

            // Restore necessary properties on which we are authoritative.
            $data['headers'][Property::MESSAGE_ID] = $id->toString();
            if (isset($data['retry_count'])) {
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
                    $this->doMarkAsFailed($id, $e);

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
            $this->doMarkAsFailed($id, $e);

            throw new \RuntimeException('Error while fetching messages', 0, $e);
        }

        return null;
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
        if ($envelope->hasProperty(Property::RETRY_COUNT)) {
            // Having a count property means the caller did already set it,
            // we will not increment it ourself.
            $count = (int) $envelope->getProperty(Property::RETRY_COUNT, "0");
            $max = (int) $envelope->getProperty(Property::RETRY_MAX, "4");

            // Prevent "0" count value from forcing a retry.
            if ($count) {
                if ($count >= $max) {
                    $this->doMarkAsFailed($envelope->getMessageId());
                } else {
                    $this->doMarkForRetry($envelope);
                }
                return;
            }
        }

        $this->doMarkAsFailed($envelope->getMessageId(), $exception);
    }

    /**
     * Get queues names from which to consume messages.
     */
    protected function getInputQueues(): array
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
            $output .= \sprintf("%s: %s in %s(%s)\n", \get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine());
            $output .= $exception->getTraceAsString();
        } while ($exception = $exception->getPrevious());

        return $output;
    }
}
