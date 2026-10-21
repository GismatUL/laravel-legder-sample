# 0007. Timeouts, retries and a circuit breaker for outbound calls

## Status

Accepted

## Context

`NotifyPaymentWebhook` posts "payment recorded" to an external system (e.g.
a customer's ERP). Receivers go down, get slow, or reject payloads. Without
limits:

- a hanging receiver holds a queue worker until the job timeout;
- every queued delivery keeps hitting a receiver that is down, slowing its
  recovery and filling the workers;
- a retry after a lost response delivers the same event twice.

## Decision

Layers, from the inside out:

1. **Timeouts:** 2 s to connect, 5 s in total (configurable).
2. **Quick retries:** 3 attempts, 250 ms apart, only for connection errors,
   5xx and 429. Other 4xx responses aren't retried, because the same payload
   won't be accepted the next time.
3. **Circuit breaker** (`Support\CircuitBreaker`): after 5 failures in a row,
   calls fail at once with `CircuitOpenException` for 60 s, without a request.
   After the cool-down one probe goes through: success closes the circuit,
   failure opens it again. State is in the cache, so all workers share it.
4. **Job retries:** the outbox job's own backoff (30 s to 30 min, 5 attempts)
   spaces out deliveries while the circuit is open.

Each request carries:

- `Idempotency-Key`: the outbox dedupe key, so the receiver can drop duplicates;
- `X-Signature: sha256=<HMAC of the body>`, so it can verify the sender.

The webhook is off unless `LEDGER_WEBHOOK_URL` is set; the message is then
marked `skipped`.

## Consequences

- A receiver outage costs a few requests, not one per queued payment per retry.
- The breaker counts in-a-row failures per process group sharing the cache.
  With Redis the counters are atomic. Two workers can both send a probe at
  the end of a cool-down, which is acceptable here.
- A 4xx still fails the delivery and goes through the job retries until the
  message is `failed`. A dead-letter review of failed messages is a manual
  step ([operations.md](../operations.md)).
- Tests fake HTTP (`Http::fake`) for each case: success with signature and
  key, skipped without a URL, 503 retried, 422 not retried, circuit opening.
