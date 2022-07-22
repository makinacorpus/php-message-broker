CREATE TABLE "message_broker" (
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

CREATE TABLE "message_broker_dead_letters" (
    "id" uuid NOT NULL,
    "serial" bigint,
    "queue" varchar(500) NOT NULL DEFAULT 'default',
    "created_at" timestamp NOT NULL DEFAULT now(),
    "consumed_at" timestamp DEFAULT NULL,
    "headers" jsonb NOT NULL DEFAULT '{}'::jsonb,
    "body" bytea NOT NULL,
    "error_code" bigint default null,
    "error_message" varchar(500) default null,
    "error_trace" text default null,
    PRIMARY KEY ("bigint")
);
