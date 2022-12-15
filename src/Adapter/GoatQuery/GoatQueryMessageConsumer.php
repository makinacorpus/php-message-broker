<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Adapter\GoatQuery;

use Goat\Query\Expression\ConstantRowExpression;
use Goat\Query\Expression\IdentifierExpression;
use Goat\Query\Expression\ValueExpression;
use Goat\Runner\Runner;
use MakinaCorpus\Message\Envelope;
use MakinaCorpus\Message\Property;
use MakinaCorpus\MessageBroker\Adapter\AbstractMessageConsumer;
use MakinaCorpus\Message\Identifier\MessageId;
use MakinaCorpus\Normalization\Serializer;

/**
 * This is a PostgreSQL implementation, which uses PostgresSQL only dialect.
 */
final class GoatQueryMessageConsumer extends AbstractMessageConsumer
{
    private Runner $runner;
    private string $schema = 'public';
    private bool $useListen = false;
    private bool $initialized = false;
    private int $emptyCheckDelai = 30;
    private string $listenChannel = 'goat_message_broker';

    /**
     * When booting, if listen is enabled, the bus will probably wait for some
     * other process to NOTIFY in order to consume messages. Problem lies in the
     * fact there might be awaiting messages already.
     *
     * In case we do wait for NOTIFY to be raised, the worker will simply do
     * nothing about what is already left in the database.
     *
     * If previous was not none, it will always attempt the UPDATE query to
     * consume a message, if previous was none, it will await for NOTIFY
     * instead. This way, until the queue contains messages, NOTIFY will be
     * ignored.
     */
    private ?float $emptiedAt = null;

    public function __construct(Runner $runner, Serializer $serializer, array $options = [])
    {
        parent::__construct($serializer, $options);

        $this->runner = $runner;
        $this->schema = $options['schema'] ?? 'public';

        if ($options['listen_enabled'] ?? false) {
            if ('pgsql' === ($driver = $runner->getDriverName())) {
                $this->useListen = true;
            } else {
                \trigger_error(\sprintf("'listen_enabled' option is set to true using driver '%s', but it only is supported by 'pgsql'.", $driver), E_USER_WARNING);
            }
        }

        if ($value = ($options['listen_channel'] ?? null)) {
            $this->listenChannel = $value;
        }

        if ($value = ($options['queue_check_delay'] ?? null)) {
            if (!\ctype_digit($value)) {
                throw new \InvalidArgumentException(\sprintf("'queue_check_delay' option must be a positive integer."));
            }
            $this->emptyCheckDelai = \abs($value * 1000);
        }
    }

    /**
     * {@inheritdoc}
     */
    private function doGetWithListen(): ?array
    {
        if (null === $this->emptiedAt || (\microtime(true) - $this->emptiedAt) < $this->emptyCheckDelai) {
            return $this->doGet();
        }

        if (!$this->initialized) {
            $this->runner->perform('LISTEN ?', [new IdentifierExpression($this->listenChannel)]);
        }

        throw new \Exception("Notification handling not implemented yet in makinacorpus/goat-query");
    }

    /**
     * {@inheritdoc}
     */
    private function doGetClassic(): ?array
    {
        $ret = $this
            ->runner
            ->execute(
                <<<SQL
                UPDATE "{$this->schema}"."message_broker"
                SET "consumed_at" = current_timestamp
                WHERE "id" IN (
                    SELECT "id"
                    FROM "{$this->schema}"."message_broker"
                    WHERE
                        "queue" IN ?
                        AND "consumed_at" IS NULL
                        AND ("retry_at" IS NULL OR "retry_at" <= current_timestamp)
                    ORDER BY "serial" ASC
                    FOR UPDATE SKIP LOCKED
                    LIMIT 1 OFFSET 0
                )
                RETURNING
                    "id",
                    "serial",
                    "headers",
                    "body"::bytea,
                    "retry_count"
                SQL,
                [new ConstantRowExpression($this->getInputQueues())]
            )
            ->fetch()
        ;

        if (!$ret) {
            $this->emptiedAt = \microtime(true);
        }

        return $ret;
    }

    /**
     * {@inheritdoc}
     */
    protected function doGet(): ?array
    {
        if ($this->useListen) {
            return $this->doGetWithListen();
        } else {
            return $this->doGetClassic();
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function doAck(Envelope $envelope): void
    {
        // Nothing to do, ACK was atomic in the UPDATE/RETURNING SQL query.
    }

    /**
     * {@inheritdoc}
     */
    protected function doMarkForRetry(Envelope $envelope): void
    {
        $delay = (int) $envelope->getProperty(Property::RETRY_DELAI);
        $count = (int) $envelope->getProperty(Property::RETRY_COUNT, "0");

        $this->runner->perform(
            <<<SQL
            UPDATE "{$this->schema}"."message_broker"
            SET
                "consumed_at" = null,
                "has_failed" = true,
                "headers" = ?::json,
                "retry_at" = current_timestamp + interval '"{$delay}" milliseconds',
                "retry_count" = GREATEST("retry_count" + 1, ?::int)
            WHERE
                "id" = ?
            SQL
            , [
                new ValueExpression($envelope->getProperties(), 'json'),
                $count,
                $envelope->getMessageId(),
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function doMarkAsFailed(MessageId $id, ?\Throwable $exception = null): void
    {
        if ($exception) {
            $this->runner->perform(
                <<<SQL
                UPDATE "{$this->schema}"."message_broker"
                SET
                    "has_failed" = true,
                    "error_code" = ?,
                    "error_message" = ?,
                    "error_trace" = ?
                WHERE
                    "id" = ?
                SQL,
                [
                    $exception->getCode(),
                    $exception->getMessage(),
                    $this->normalizeExceptionTrace($exception),
                    $id->toString(),
                ]
            );
        } else {
            $this->runner->perform(
                <<<SQL
                UPDATE "{$this->schema}"."message_broker"
                SET
                    "has_failed" = true
                WHERE
                    "id" = ?
                SQL,
                [
                    $id->toString(),
                ]
            );
        }
    }
}
