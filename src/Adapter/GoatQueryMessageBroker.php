<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Adapter;

use Goat\Runner\Runner;
use MakinaCorpus\Message\Envelope;
use MakinaCorpus\Message\Property;
use MakinaCorpus\Normalization\Serializer;

/**
 * This is a PostgreSQL implementation, which uses PostgresSQL only dialect.
 */
final class GoatQueryMessageBroker extends AbstractMessageBroker
{
    private Runner $runner;

    public function __construct(Runner $runner, Serializer $serializer, array $options = [])
    {
        parent::__construct($serializer, $options);

        $this->runner = $runner;
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
                UPDATE "message_broker"
                SET
                    "consumed_at" = now()
                WHERE
                    "id" IN (
                        SELECT "id"
                        FROM "message_broker"
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
                [$this->getQueue()]
            )
            ->fetch()
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function doSend(Envelope $envelope, string $serializedBody): void
    {
        $this->runner->execute(
            <<<SQL
            INSERT INTO "message_broker"
                (id, queue, headers, body)
            VALUES
                (?::uuid, ?::string, ?::json, ?::bytea)
            SQL
           , [
               $envelope->getMessageId()->toString(),
               $this->getQueue(),
               $envelope->getProperties(),
               $serializedBody,
           ]
        );
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
        $serial = (int) $envelope->getProperty(self::PROP_SERIAL);
        $delay = (int) $envelope->getProperty(Property::RETRY_DELAI);
        $count = (int) $envelope->getProperty(Property::RETRY_COUNT, "0");

        $this->runner->execute(
            <<<SQL
            UPDATE "message_broker"
            SET
                "consumed_at" = null,
                "has_failed" = true,
                "headers" = ?::json,
                "retry_at" = current_timestamp + interval '"{$delay}" milliseconds',
                "retry_count" = GREATEST("retry_count" + 1, ?::int)
            WHERE
                "serial" = ?::int
            SQL
            , [
                $envelope->getProperties(),
                $count,
                $serial,
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function doMarkAsFailed(Envelope $envelope, ?\Throwable $exception = null): void
    {
        $serial = (int) $envelope->getProperty(self::PROP_SERIAL);

        if ($exception) {
            $this->runner->execute(
                <<<SQL
                UPDATE "message_broker"
                SET
                    "has_failed" = true,
                    "error_code" = ?,
                    "error_message" = ?,
                    "error_trace" = ?
                WHERE
                    "serial" = ?
                SQL,
                [
                    $exception->getCode(),
                    $exception->getMessage(),
                    $this->normalizeExceptionTrace($exception),
                    $serial,
                ]
            );
        } else {
            $this->runner->execute(
                <<<SQL
                UPDATE "message_broker"
                SET
                    "has_failed" = true
                WHERE
                    "serial" = ?
                SQL,
                [
                    $serial,
                ]
            );
        }
    }
}
