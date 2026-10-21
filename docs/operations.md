# Operations

How to run, observe and recover the application. Commands assume the Docker
setup (`docker compose exec app ...`); without Docker, drop the prefix.

## Health

| Check | How | Healthy |
|---|---|---|
| App boots | `GET /up` | `200` |
| Ledger consistent | `php artisan ledger:trial-balance` | exit code 0, "Debits equal credits." |
| Queue alive | `docker compose ps queue` and its logs | running, jobs `DONE` |
| Nothing stuck | `php artisan queue:failed`; outbox query below | empty |

## Logs

In Docker every line is one JSON object on stderr:

```json
{"message":"Payment recorded","context":{"payment_id":3,"invoice_id":2,"amount_minor":1000,"invoice_status":"partially_paid"},"level_name":"INFO","extra":{"request_id":"demo-req-67890"}}
```

Follow one request across the web process and the workers:

```bash
docker compose logs app queue scheduler | grep '"request_id":"demo-req-67890"'
```

The id is in the `X-Request-Id` response header. A client or gateway can send
its own id in the same header (see [ADR 0008](adr/0008-request-correlation-and-json-logs.md)).

Logs never contain customer email addresses or full SQL errors; use the ids
in the log line to look the records up.

## Failed outbox messages

Statuses: `pending` → `sent` / `skipped`, or `failed` after 5 attempts.

```sql
SELECT id, type, dedupe_key, attempts, last_error, updated_at
FROM outbox_messages
WHERE status = 'failed' OR (status = 'pending' AND attempts > 0)
ORDER BY id;
```

1. Read `last_error`. A `CircuitOpenException` means the receiver failed
   repeatedly (see below). A 4xx means the receiver rejected the payload, and
   retrying won't help until the cause is fixed.
2. Fix the cause (receiver back up, configuration, data).
3. Put the messages back and re-dispatch them:

   ```sql
   UPDATE outbox_messages SET status = 'pending', claimed_at = NULL WHERE id IN (...);
   ```

   ```bash
   php artisan outbox:relay --older-than=0
   ```

Delivery is at least once, so a re-sent webhook carries the same
`Idempotency-Key` and the receiver drops it if it already processed it.

## Webhook receiver down

The circuit breaker opens after `LEDGER_WEBHOOK_CIRCUIT_THRESHOLD` failures in
a row and stays open for `LEDGER_WEBHOOK_CIRCUIT_COOL_DOWN` seconds. While it
is open, deliveries fail at once and the queue retries them with backoff; no
action is needed if the receiver recovers within about 40 minutes (the job
backoffs add up to 42.5 minutes). After that, messages become `failed`: follow the section
above.

To stop deliveries entirely, clear `LEDGER_WEBHOOK_URL`. New messages are then
marked `skipped`, which is final, so do this only when they should not be sent.

## Failed jobs

```bash
php artisan queue:failed
php artisan queue:retry <id>     # or: all
```

`ReconcileBankTransactions` is safe to retry: reconciled lines are skipped and
`bank_transactions.payment_id` is unique.

## Cache

Reports are cached in Redis and invalidated on every posting
([ADR 0006](adr/0006-report-cache-invalidation.md)). If a report ever looks
stale:

```bash
php artisan cache:clear
```

This also resets the webhook circuit breaker and rate limits.

## Deploying a change

```bash
docker compose build
docker compose up -d                       # restarts app, queue and scheduler on the new image
docker compose exec app php artisan migrate --force
```

The queue and scheduler containers start from the new image, so they never
run old code. Without Docker, run `php artisan queue:restart` after deploying.

## Not covered

No metrics, alerting or tracing are set up. A production deployment would
alert on: `failed_jobs` rows, `outbox_messages` with status `failed`, the
trial balance check failing, and error-level log lines.
