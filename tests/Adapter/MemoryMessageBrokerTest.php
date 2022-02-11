<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Tests\Adpater;

use Goat\Runner\Runner;
use Goat\Runner\Testing\TestDriverFactory;
use MakinaCorpus\MessageBroker\MessageBroker;
use MakinaCorpus\MessageBroker\Adapter\MemoryMessageBroker;
use MakinaCorpus\MessageBroker\Tests\Adapter\AbstractMessageBrokerTest;

final class MemoryMessageBrokerTest extends AbstractMessageBrokerTest
{
    /**
     * {@inheritdoc}
     */
    protected function createItemInQueue(MessageBroker $messageBroker, array $data): void
    {
        \assert($messageBroker instanceof MemoryMessageBroker);

        $messageBroker->pushArbitraryValues($data['id'], $data['body'], $data['headers']);
    }

    /**
     * {@inheritdoc}
     */
    protected function createMessageBroker(Runner $runner, string $schema): MessageBroker
    {
        return new MemoryMessageBroker($this->createSerializer());
    }

    /**
     * @dataProvider runnerDataProvider
     */
    public function testRejectWithRetryDelayInFarFutureIsNotGetRightNow(TestDriverFactory $factory): void
    {
        self::markTestSkipped("Memory implementation doesn't care about time.");
    }
}
