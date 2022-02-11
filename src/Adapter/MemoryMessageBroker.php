<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Adapter;

use MakinaCorpus\Message\Envelope;
use MakinaCorpus\Message\Identifier\MessageId;
use MakinaCorpus\Message\Property;

/**
 * This is a very inneficient implementation, use it only for unit tests.
 */
final class MemoryMessageBroker extends AbstractMessageBroker
{
    private int $serial = 1;
    private array $consumed = [];
    private array $waiting = [];

    public function pushArbitraryValues(mixed $id, string $serializedBody, array $headers): void
    {
        $id = (string) $id;

        $this->waiting[$id] = [
            'body' => $serializedBody,
            'headers' => $headers,
            'id' => $id,
            'retry' => false,
            'serial' => $this->serial++,
            'success' => 0,
            'retry_count' => 0,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function doGet(): ?array
    {
        foreach ($this->waiting as $id => $next) {
            unset($this->waiting[$id]);
            $this->consumed[$next['id']] = $next;

            return $next;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    protected function doSend(Envelope $envelope, string $serializedBody): void
    {
        $this->pushArbitraryValues($envelope->getMessageId(), $serializedBody, $envelope->getProperties());
    }

    /**
     * {@inheritdoc}
     */
    protected function doAck(Envelope $envelope): void
    {
        $id = $envelope->getMessageId()->toString();

        $item = $this->consumed[$id] ?? null;

        if ($item) {
            $this->consumed[$id]['success'] = 1;
            $this->consumed[$id]['success'] = $envelope->getProperties();
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function doMarkForRetry(Envelope $envelope): void
    {
        $id = $envelope->getMessageId()->toString();

        if (isset($this->consumed[$id])) {
            $item = $this->consumed[$id];

            unset($this->consumed[$id]);

            $item['success'] = -1;
            $item['retry'] = true;
            $item['headers'] = $envelope->getProperties();

            // Handle retry count and fix it.
            $item['retry_count']++;
            $item['headers'][Property::RETRY_COUNT] = $item['retry_count'];

            $this->waiting[$id] = $item;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function doMarkAsFailed(MessageId $id, ?\Throwable $exception = null): void
    {
        $id = $id->toString();

        if (isset($this->consumed[$id])) {
            $this->consumed[$id]['success'] = -1;
            $this->consumed[$id]['retry'] = false;
        }
    }

    /**
     * Get a single item by identifier.
     */
    private function getById(mixed $id): ?array
    {
        $id = (string) $id;

        return $this->consuming[$id] ?? $this->waiting[$id] ?? $this->consuming[$id];
    }
}
