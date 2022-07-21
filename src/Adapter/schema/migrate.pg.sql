-- In order to migrate from makinacorpus/goat 3.x and earlier versions.

ALTER TABLE "message_broker" ADD COLUMN
    "error_code" bigint default null;
ALTER TABLE "message_broker" ADD COLUMN
    "error_message" text default null;
ALTER TABLE "message_broker" ADD COLUMN
    "error_trace" text default null;

ALTER TABLE "message_broker_dead_letters" ADD COLUMN
    "error_code" bigint default null;
ALTER TABLE "message_broker_dead_letters" ADD COLUMN
    "error_message" text default null;
ALTER TABLE "message_broker_dead_letters" ADD COLUMN
    "error_trace" text default null;


-- @todo We are going to miss a request that moves
--   the "type" and "content_type" column values into
--   "headers" jsonb value.

ALTER TABLE "message_broker" DROP COLUMN IF EXISTS "content_type";
ALTER TABLE "message_broker" DROP COLUMN IF EXISTS "type";

ALTER TABLE "message_broker" ALTER COLUMN "error_message" TYPE text;

ALTER TABLE "message_broker" DROP COLUMN IF EXISTS "message_broker_dead_letters";
ALTER TABLE "message_broker" DROP COLUMN IF EXISTS "message_broker_dead_letters";
