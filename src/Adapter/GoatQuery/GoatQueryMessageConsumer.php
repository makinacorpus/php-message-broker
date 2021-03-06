<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Adapter\GoatQuery;

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

    public function __construct(Runner $runner, Serializer $serializer, array $options = [])
    {
        parent::__construct($serializer, $options);

        $this->runner = $runner;
        $this->schema = $options['schema'] ?? 'public';
    }

    /**
     * {@inheritdoc}
     */
    protected function doGet(): ?array
    {
        return $this
            ->runner
            ->execute(
                <<<SQL
                UPDATE "{$this->schema}"."message_broker"
                SET
                    "consumed_at" = now()
                WHERE
                    "id" IN (
                        SELECT "id"
                        FROM "{$this->schema}"."message_broker"
                        WHERE
                            "queue" = ?::string
                            AND "consumed_at" IS NULL
                            AND ("retry_at" IS NULL OR "retry_at" <= current_timestamp)
                        ORDER BY
                            "serial" ASC
                        LIMIT 1 OFFSET 0
                    )
                    AND "consumed_at" IS NULL
                RETURNING
                    "id",
                    "serial",
                    "headers",
                    "body"::bytea,
                    "retry_count"
                SQL,
                $this->getInputQueues()
            )
            ->fetch()
        ;
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
                $envelope->getProperties(),
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
