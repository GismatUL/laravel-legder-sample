# 0002. Append-only ledger, corrections by reversing entries

## Status

Accepted

## Context

An accounting ledger is an audit record. If a posted entry can be edited,
past reports change silently and nobody can show what the books said on a
given day. Invoices and payments also post to the ledger, so a document and
its postings must never disagree.

## Decision

- `LedgerService` is the only class that writes journal entries. It checks
  the whole entry (at least two lines, one side per line, active accounts,
  debits = credits) and writes it in one transaction.
- Posted entries can't be updated or deleted. Three layers refuse it: model
  events throw, policies deny it, and foreign keys restrict deletes.
- A mistake is corrected with a reversing entry linked to the original
  (`reversal_of_id`, unique, so an entry is reversed at most once).
- Entries created by a document are reversed through the document (voiding
  the invoice), never directly.
- Manual entries can't post to control accounts (receivables), so the
  receivables balance always equals the open invoices.
- Who posted each entry is recorded (`posted_by`).

## Consequences

- Reports for a past date stay reproducible.
- Corrections are visible: the trial balance includes both the original and
  its reversal.
- There are no accounting periods: an entry can still be back-dated, which is
  listed as a limitation. Period closing would add a check in `LedgerService`.
- Reports that depend on the ledger must be invalidated on every posting
  ([ADR 0006](0006-report-cache-invalidation.md)), which the append-only rule
  makes simple: postings are the only change.
