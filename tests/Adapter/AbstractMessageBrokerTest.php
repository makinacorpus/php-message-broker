<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Tests\Adapter;

use Goat\Runner\Testing\DatabaseAwareQueryTest;
use Goat\Runner\Testing\TestDriverFactory;
use MakinaCorpus\Message\BrokenEnvelope;
use MakinaCorpus\Message\Envelope;
use MakinaCorpus\Message\Property;
use MakinaCorpus\MessageBroker\MessageConsumerFactory;
use MakinaCorpus\MessageBroker\MessagePublisher;
use MakinaCorpus\MessageBroker\Tests\Mock\MockMessage;
use MakinaCorpus\Message\Identifier\MessageIdFactory;
use MakinaCorpus\Normalization\Testing\WithSerializerTestTrait;

abstract class AbstractMessageBrokerTest extends DatabaseAwareQueryTest
{
    use WithSerializerTestTrait;

    /**
     * @dataProvider runnerDataProvider
     */
    public function testGetWhenEmptyGivesNull(TestDriverFactory $factory): void
    {
        $consumerFactory = $this->createMessageConsumerFactory($factory);
        $consumerDefault = $consumerFactory->createConsumer();

        self::assertNull($consumerDefault->get());
    }

    /**
     * @dataProvider runnerDataProvider
     */
    public function testGetFetchTheFirstOne(TestDriverFactory $factory): void
    {
        $publisher = $this->createMessagePublisher($factory);
        $consumerFactory = $this->createMessageConsumerFactory($factory);
        $consumerDefault = $consumerFactory->createConsumer();
        $consumerOther = $consumerFactory->createConsumer(['other_queue']);

        $publisher->dispatch(Envelope::wrap(new MockMessage()));
        $publisher->dispatch(Envelope::wrap(new \DateTime()));
        $publisher->dispatch(Envelope::wrap(new \DateTimeImmutable()), 'other_queue');

        $envelope1 = $consumerDefault->get();
        self::assertSame(MockMessage::class, $envelope1->getProperty(Property::MESSAGE_TYPE));

        $envelope2 = $consumerOther->get();
        self::assertSame(\DateTimeImmutable::class, $envelope2->getProperty(Property::MESSAGE_TYPE));

        $envelope3 = $consumerDefault->get();
        self::assertSame(\DateTime::class, $envelope3->getProperty(Property::MESSAGE_TYPE));

        self::assertNull($consumerDefault->get());

        self::assertNull($consumerOther->get());
    }

    /**
     * @dataProvider runnerDataProvider
     */
    public function testAutomaticPropertiesAreComputed(TestDriverFactory $factory): void
    {
        $publisher = $this->createMessagePublisher($factory);
        $consumerFactory = $this->createMessageConsumerFactory($factory);
        $consumerDefault = $consumerFactory->createConsumer();

        $publisher->dispatch(Envelope::wrap(new MockMessage()));

        $envelope = $consumerDefault->get();

        self::assertSame(MockMessage::class, $envelope->getProperty(Property::MESSAGE_TYPE));
        self::assertNotInstanceOf(BrokenEnvelope::class, $envelope);
        self::assertSame(Property::DEFAULT_CONTENT_ENCODING, $envelope->getMessageContentEncoding());
        self::assertSame(Property::DEFAULT_CONTENT_TYPE, $envelope->getMessageContentType());
        self::assertNotNull($envelope->getMessageId());
    }

    /**
     * @dataProvider runnerDataProvider
     */
    public function testContentTypeFromEnvelopeIsUsed(TestDriverFactory $factory): void
    {
        $publisher = $this->createMessagePublisher($factory);
        $consumerFactory = $this->createMessageConsumerFactory($factory);
        $consumerDefault = $consumerFactory->createConsumer();

        $publisher->dispatch(Envelope::wrap(new MockMessage(), [
            Property::CONTENT_TYPE => 'application/xml',
        ]));

        $envelope = $consumerDefault->get();

        // This will fail if we change the default, just to be sure we do
        // really test the correct behaviour.
        self::assertNotSame(Property::DEFAULT_CONTENT_TYPE, 'application/xml');

        self::assertSame(MockMessage::class, $envelope->getProperty(Property::MESSAGE_TYPE));
        self::assertNotInstanceOf(BrokenEnvelope::class, $envelope->getMessage());
        self::assertSame('application/xml', $envelope->getMessageContentType());
    }

    /**
     * @dataProvider runnerDataProvider
     */
    public function testPropertiesArePropagated(TestDriverFactory $factory): void
    {
        $publisher = $this->createMessagePublisher($factory);
        $consumerFactory = $this->createMessageConsumerFactory($factory);
        $consumerDefault = $consumerFactory->createConsumer();

        $publisher->dispatch(Envelope::wrap(new MockMessage(), [
            'x-foo' => 'bar',
        ]));

        $envelope = $consumerDefault->get();

        self::assertSame('bar', $envelope->getProperty('x-foo'));
    }

    /**
     * @dataProvider runnerDataProvider
     */
    public function testFailedMessagesAreNotGetAgain(TestDriverFactory $factory): void
    {
        $publisher = $this->createMessagePublisher($factory);
        $consumerFactory = $this->createMessageConsumerFactory($factory);
        $consumerDefault = $consumerFactory->createConsumer();

        $publisher->dispatch(Envelope::wrap(new MockMessage()));

        $envelope = $consumerDefault->get();
        $consumerDefault->reject($envelope);

        self::assertNull($consumerDefault->get());
    }

    /**
     * @dataProvider runnerDataProvider
     */
    public function testRejectWithRetryCountIsRequeued(TestDriverFactory $factory): void
    {
        $publisher = $this->createMessagePublisher($factory);
        $consumerFactory = $this->createMessageConsumerFactory($factory);
        $consumerDefault = $consumerFactory->createConsumer();

        $publisher->dispatch(Envelope::wrap(new MockMessage()));

        $originalEnvelope = $consumerDefault->get();

        $consumerDefault->reject($originalEnvelope->withProperties([
            Property::RETRY_COUNT => "1",
        ]));

        $envelope = $consumerDefault->get();

        self::assertSame("1", $envelope->getProperty(Property::RETRY_COUNT));
        self::assertTrue($originalEnvelope->getMessageId()->equals($envelope->getMessageId()));
    }

    /**
     * @dataProvider runnerDataProvider
     */
    public function testRejectWithRetryDelayInFarFutureIsNotGetRightNow(TestDriverFactory $factory): void
    {
        $publisher = $this->createMessagePublisher($factory);
        $consumerFactory = $this->createMessageConsumerFactory($factory);
        $consumerDefault = $consumerFactory->createConsumer();

        $publisher->dispatch(Envelope::wrap(new MockMessage()));

        $originalEnvelope = $consumerDefault->get();

        $consumerDefault->reject($originalEnvelope->withProperties([
            Property::RETRY_COUNT => "1",
            Property::RETRY_DELAI => "100000000",
        ]));

        $envelope = $consumerDefault->get();
        self::assertNull($envelope);
    }

    /**
     * @dataProvider runnerDataProvider
     */
    public function testRejectWithLowerRetryCountGetsFixed(TestDriverFactory $factory): void
    {
        $publisher = $this->createMessagePublisher($factory);
        $consumerFactory = $this->createMessageConsumerFactory($factory);
        $consumerDefault = $consumerFactory->createConsumer();

        $publisher->dispatch(Envelope::wrap(new MockMessage()));

        $originalEnvelope = $consumerDefault->get();
        $consumerDefault->reject($originalEnvelope->withProperties([
            Property::RETRY_COUNT => "1",
        ]));

        $secondEnvelope = $consumerDefault->get();
        $consumerDefault->reject($secondEnvelope->withProperties([
            Property::RETRY_COUNT => "1",
        ]));

        $thirdEnvelope = $consumerDefault->get();
        $consumerDefault->reject($thirdEnvelope->withProperties([
            Property::RETRY_COUNT => "1",
        ]));

        $envelope = $consumerDefault->get();
        self::assertSame("3", $envelope->getProperty(Property::RETRY_COUNT));
    }

    /**
     * @dataProvider runnerDataProvider
     */
    public function testMissingTypeInDatabaseFallsBackWithHeader(TestDriverFactory $factory): void
    {
        $publisher = $this->createMessagePublisher($factory);
        $consumerFactory = $this->createMessageConsumerFactory($factory);
        $consumerDefault = $consumerFactory->createConsumer();

        $this->createItemInQueue($publisher, [
            'id' => MessageIdFactory::generate()->toString(),
            'headers' => [
                Property::CONTENT_TYPE => 'application/json',
                Property::MESSAGE_TYPE => MockMessage::class,
            ],
            'body' => '{}',
        ]);

        $envelope = $consumerDefault->get();

        self::assertSame(MockMessage::class, $envelope->getProperty(Property::MESSAGE_TYPE));
        self::assertInstanceOf(MockMessage::class, $envelope->getMessage());
    }

    /**
     * @dataProvider runnerDataProvider
     */
    public function testMissingContentTypeInDatabaseFallsBackWithHeader(TestDriverFactory $factory): void
    {
        $publisher = $this->createMessagePublisher($factory);
        $consumerFactory = $this->createMessageConsumerFactory($factory);
        $consumerDefault = $consumerFactory->createConsumer();

        $this->createItemInQueue($publisher, [
            'id' => MessageIdFactory::generate()->toString(),
            'headers' => [
                Property::CONTENT_TYPE => 'application/json',
                Property::MESSAGE_TYPE => MockMessage::class,
            ],
            'body' => '{}',
        ]);

        $envelope = $consumerDefault->get();

        self::assertSame(MockMessage::class, $envelope->getProperty(Property::MESSAGE_TYPE));
        self::assertInstanceOf(MockMessage::class, $envelope->getMessage());
    }

    /**
     * @dataProvider runnerDataProvider
     */
    public function testNoTypeGivesBrokenEnvelope(TestDriverFactory $factory): void
    {
        $publisher = $this->createMessagePublisher($factory);
        $consumerFactory = $this->createMessageConsumerFactory($factory);
        $consumerDefault = $consumerFactory->createConsumer();

        $this->createItemInQueue($publisher, [
            'id' => MessageIdFactory::generate()->toString(),
            'headers' => [
                Property::CONTENT_TYPE => 'application/json',
            ],
            'body' => '{}',
        ]);

        $envelope = $consumerDefault->get();

        self::assertNull($envelope->getProperty(Property::MESSAGE_TYPE));
        self::assertInstanceOf(BrokenEnvelope::class, $envelope);
        self::assertSame('{}', $envelope->getMessage());
    }

    /**
     * @dataProvider runnerDataProvider
     */
    public function testNoContentTypeGivesBrokenEnvelope(TestDriverFactory $factory): void
    {
        $publisher = $this->createMessagePublisher($factory);
        $consumerFactory = $this->createMessageConsumerFactory($factory);
        $consumerDefault = $consumerFactory->createConsumer();

        $this->createItemInQueue($publisher, [
            'id' => MessageIdFactory::generate()->toString(),
            'headers' => [
                Property::MESSAGE_TYPE => MockMessage::class,
            ],
            'body' => '{}',
        ]);

        $envelope = $consumerDefault->get();

        self::assertSame(MockMessage::class, $envelope->getProperty(Property::MESSAGE_TYPE));
        self::assertInstanceOf(BrokenEnvelope::class, $envelope);
        self::assertSame('{}', $envelope->getMessage());
    }

    /**
     * Create an arbitrary item in queue.
     */
    protected abstract function createItemInQueue(MessagePublisher $publisher, array $data): void;

    /**
     * Create message broker instance that will be tested.
     */
    protected abstract function createMessagePublisher(TestDriverFactory $factory): MessagePublisher;

    /**
     * Create message broker instance that will be tested.
     */
    protected abstract function createMessageConsumerFactory(TestDriverFactory $factory): MessageConsumerFactory;
}
