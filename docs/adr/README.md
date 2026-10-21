# Architecture decision records

Each record states one decision, the context it was made in, and what it
costs. Format: [Michael Nygard's template](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions).

| # | Decision | Status |
|---|---|---|
| [0001](0001-money-as-integer-minor-units.md) | Money as integer minor units, never floats | Accepted |
| [0002](0002-append-only-ledger.md) | Append-only ledger, corrections by reversing entries | Accepted |
| [0003](0003-modular-monolith.md) | A modular monolith, not microservices | Accepted |
| [0004](0004-locking-and-idempotency.md) | Row locks and idempotency keys for financial writes | Accepted |
| [0005](0005-transactional-outbox.md) | Side effects through a transactional outbox | Accepted |
| [0006](0006-report-cache-invalidation.md) | Report cache invalidated by a version number | Accepted |
| [0007](0007-outbound-webhook-resilience.md) | Timeouts, retries and a circuit breaker for outbound calls | Accepted |
| [0008](0008-request-correlation-and-json-logs.md) | Request ids through Laravel Context, JSON logs | Accepted |
