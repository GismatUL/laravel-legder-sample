# 0006. Report cache invalidated by a version number

## Status

Accepted

## Context

The trial balance aggregates every journal line in one grouped SQL query. It
grows with the ledger and is read far more often than the ledger changes.
Two things make caching it tricky:

- **Correctness:** a cached report must never hide a posting. Accountants
  check the trial balance right after posting.
- **Back-dating:** an entry can be dated in the past, so it changes the
  report for every `as_of` date after it, not only today's.

Deleting the right keys would mean tracking every cached date. Cache tags
would do it, but the database and file drivers don't support them.

## Decision

- `ReportCache` puts a version number in every key:
  `reports:v{version}:trial-balance:{as_of}`.
- `LedgerService` dispatches `LedgerPosted` for every entry. The event
  implements `ShouldDispatchAfterCommit`, so it's dropped when the transaction
  rolls back.
- The `InvalidateReportCache` listener increments the version. It runs
  synchronously, because a queued listener would leave a window with a stale
  report. Renaming an account invalidates too, since reports show names.
- Old versions are never deleted. They expire after the TTL
  (`LEDGER_REPORT_CACHE_SECONDS`, 10 minutes).
- Values are cached as plain arrays. Laravel 13 refuses to unserialize
  objects from the cache by default (`cache.serializable_classes = false`).
  Caching value objects worked with the array store in tests and failed on
  Redis; running the suite against Redis caught it.

## Consequences

- A posting costs one `INCR`. Report reads after it miss once and are cached again.
- Any posting clears every cached report, which is coarse but simple and
  correct. Per-account or per-period keys would be the next step if postings
  became frequent enough to make the cache useless.
- The version key itself has no TTL; losing it (cache flush) only means a
  cold cache.
