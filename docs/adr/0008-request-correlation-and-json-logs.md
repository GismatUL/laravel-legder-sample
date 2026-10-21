# 0008. Request ids through Laravel Context, JSON logs

## Status

Accepted

## Context

One API call can produce log lines in three processes: PHP-FPM handling the
request, a queue worker delivering its outbox message, and the scheduler
re-dispatching it. Without a shared id, a failed webhook can't be traced back
to the request that caused it. Plain-text logs are also hard to search or
ship to a log platform.

## Decision

- `AssignRequestId` is the first global middleware. It keeps a valid
  `X-Request-Id` from the caller (8–64 characters of `[A-Za-z0-9._-]`), so an
  id from a gateway follows the request across systems. Anything else is
  replaced with a UUID. The id is returned in the response header.
- The id is stored with `Context::add()`. Laravel writes Context into every
  log record (`extra.request_id`) and serializes it into queued jobs, so the
  worker's log lines carry the same id.
- In Docker, logs go to stderr as one JSON object per line
  (`LOG_CHANNEL=stderr`, `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`).
- Business events are logged with ids and amounts, never customer data:
  e.g. `Payment recorded` with `payment_id`, `invoice_id`, `amount_minor`.
  Errors from SQL keep only the SQLSTATE, because the message holds bank
  references and amounts.

## Consequences

- `docker compose logs | grep <request id>` shows one request end to end.
- Any log collector that reads container output (Loki, ELK, CloudWatch) can
  index the JSON fields without parsing.
- No metrics or distributed tracing. The request id is the correlation key a
  tracing setup would extend.
