<?php

declare(strict_types=1);

namespace MakinaCorpus\MessageBroker\Adapter\GoatQuery;

use Goat\Driver\Error\TableDoesNotExistError;
use Goat\Query\Expression\TableExpression;
use Goat\Runner\Runner;

trait GoatQueryTrait
{
    private Runner $runner;
    private string $schema = 'public';
    private string $tableName = 'message_broker';
    private ?bool $tableExists =  null;

    /**
     * Create table expression.
     */
    protected function createTableExpression(): TableExpression
    {
        return new TableExpression($this->tableName, 'message_broker', $this->schema);
    }

    /**
     * Ensure table exists.
     */
    protected function checkTable(): void
    {
        if (null == $this->tableExists) {
            try {
                $this->runner->execute("SELECT 1 FROM ?", [$this->createTableExpression()]);
            } catch (TableDoesNotExistError $e) {
                try {
                    $this->runner->execute(
                        <<<SQL
                        CREATE TABLE ? (
                            "id" uuid NOT NULL,
                            "serial" bigserial NOT NULL,
                            "queue" varchar(500) NOT NULL DEFAULT 'default',
                            "created_at" timestamp NOT NULL DEFAULT now(),
                            "consumed_at" timestamp DEFAULT NULL,
                            "has_failed" bool DEFAULT false,
                            "headers" jsonb NOT NULL DEFAULT '{}'::jsonb,
                            "body" bytea NOT NULL,
                            "error_code" bigint default null,
                            "error_message" varchar(500) default null,
                            "error_trace" text default null,
                            "retry_count" bigint DEFAULT 0,
                            "retry_at" timestamp DEFAULT NULL,
                            PRIMARY KEY ("serial")
                        );
                        SQL,
                        [$this->createTableExpression()]
                    );
                } catch (\Throwable $e) {
                    $this->tableExists = false;

                    throw $e;
                }
            }
        }

        if (false === $this->tableExists) {
            throw new \RuntimeException(\sprintf('Table "%s"."%s" does not exist.', $this->schema, $this->tableName));
        }
    }
}
