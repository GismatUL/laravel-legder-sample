# 0005. Side effects through a transactional outbox

## Status

Accepted

## Context

Issuing an invoice emails the customer; recording a payment notifies an
external system. A database transaction can't include an SMTP or HTTP call:

- sending inside the transaction means a rollback after the email leaves the
  customer with an email for an invoice that doesn't exist, and a slow
  receiver holds row locks;
- dispatching a job after commit without a record loses the side effect if
  the process dies between commit and dispatch.

A message broker (RabbitMQ, Kafka) would still need the same pattern to
publish reliably, so it doesn't remove the problem.

## Decision

- The service writes an `outbox_messages` row in the same transaction as the
  business change (`Outbox::record()`), with a unique `dedupe_key`.
- `DeliverOutboxMessage` is dispatched after commit. It claims the row with
  one conditional `UPDATE` (no lock held during delivery), calls the handler
  for the message type (`config/ledger.php`), and records sent, skipped or
  the last error.
- A claim expires after 10 minutes, so a dead worker's message is picked up
  again. `outbox:relay` (every 5 minutes) re-dispatches messages left pending.
- 5 attempts with backoff (30 s to 30 min); then the status is `failed`.

## Consequences

- No side effect for a rolled-back change, and none is lost after a commit.
- Delivery is **at least once**: a worker that crashes after sending but
  before marking the row sends again. Receivers must deduplicate, so webhook
  requests carry the dedupe key as `Idempotency-Key`. SMTP can't, so an email
  can rarely arrive twice (listed as a limitation).
- One more table to monitor: failed messages need an alert in a real
  deployment ([operations.md](../operations.md)).
