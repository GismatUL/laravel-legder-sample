# 0004. Row locks and idempotency keys for financial writes

## Status

Accepted

## Context

Two failure modes matter most for money:

- **Concurrency:** two requests pay the same invoice at the same moment. Each
  reads "outstanding 118.00", each records 118.00, and the invoice is paid twice.
- **Retries:** a client times out, doesn't know whether the payment was
  saved, and sends it again.

MySQL InnoDB runs at REPEATABLE READ by default. Plain reads inside a
transaction see a snapshot, so a check-then-write without a lock is a race.

## Decision

- **Pessimistic locks where a row exists.** Payments, issues and voids start
  with `SELECT ... FOR UPDATE` on the invoice, so requests for one invoice run
  one after another. Invoice numbers come from a counter row locked in the
  same transaction, so a rollback also returns the number (no gaps).
- **A fixed lock order:** payments lock the invoice first; matching locks the
  bank line, then the payment. No network call happens while a lock is held.
- **Idempotency keys.** `Idempotency-Key` is required for payments and
  optional for manual entries. Each has a unique index.
  - For payments, the key is checked after the invoice lock, so a duplicate
    waits and then finds the first payment.
  - For manual entries there's no row to lock, so the unique index decides.
    The loser catches the violation and re-reads the winner with a locking
    read, because a plain read in REPEATABLE READ can return an older snapshot.
  - A replay must describe the same operation: every field is compared, and a
    reused key with different data is a 409, not a silent replay.
- **Deadlock retries:** top-level financial transactions are retried up to 3
  times on a deadlock (`LedgerService::DEADLOCK_ATTEMPTS`). Everything inside
  rolled back, so a retry is safe.

## Consequences

- Writes to one invoice are serialized, which is fine for this load.
- SQLite ignores `lockForUpdate()`, so the suite also runs on MySQL, and four
  concurrency tests start 6 parallel PHP processes. They found a real
  deadlock (an `INSERT IGNORE` on every sequence call took a shared lock that
  two transactions then tried to upgrade), which is fixed.
- Keys are unique across the database, which is fine for one organization;
  multi-tenancy would add the tenant id to every unique index.
