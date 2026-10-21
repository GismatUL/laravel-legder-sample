# 0003. A modular monolith, not microservices

## Status

Accepted

## Context

The domain has three parts with different change rates: the ledger (stable
rules), billing (invoices, payments) and banking (statement import,
matching). They share data constantly: a payment is a billing record and a
ledger posting at the same time, and both must commit together or not at all.

Splitting them into services would turn those local transactions into
distributed ones (a saga with compensations for every failure), add network
calls inside financial workflows, and need a broker and per-service
deployments. For one team and one organization's data, that cost buys
nothing yet.

## Decision

- One Laravel application and one MySQL database.
- Layers inside it: controllers and Filament pages → policies and form
  requests → services (business rules, transactions) → repositories (queries,
  locks, aggregates) → Eloquent.
- Modules with one-way dependencies: Banking → Billing → Ledger. The ledger
  doesn't know invoices exist; it accepts an optional polymorphic `source` and
  announces postings with a `LedgerPosted` event.
- Work that leaves the process (email, webhooks) goes through the outbox and
  queue ([ADR 0005](0005-transactional-outbox.md)), which is also where a
  future split would cut.

## Consequences

- A payment, its posting and its outbox message commit atomically with plain
  database transactions.
- Everything deploys together; a slow module can't be scaled on its own.
- Module boundaries are a convention, not enforced by the language. A
  dependency check (e.g. deptrac) would be the next step if the codebase grew.
- If banking ever had to scale separately (high statement volume), it could
  become a service consuming `LedgerPosted`/outbox events, because it already
  talks to the rest only through services and events.
